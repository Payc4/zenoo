<?php

namespace App\Tests\Functional;

use App\Entity\ChatConversation;
use App\Entity\Course;
use App\Entity\Lesson;
use App\Entity\LessonProgress;
use App\Tests\Support\FakeClaude;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class CourseTest extends AppTestCase
{
    private function import(array $options = []): CommandTester
    {
        $tester = new CommandTester((new Application(static::$kernel))->find('app:course:import'));
        $tester->execute($options);
        $this->em->clear();
        return $tester;
    }

    private function course(string $slug = 'dog-wellness'): Course
    {
        return $this->em->getRepository(Course::class)->findOneBy(['slug' => $slug]);
    }

    private function publish(): void
    {
        $this->course()->setIsPublished(true);
        $this->em->flush();
    }

    public function testImportCreatesEveryLanguageAsDraftsAndNeverOverwritesByDefault(): void
    {
        $out = $this->import()->getDisplay();
        $this->assertStringContainsString('30 lessons created', $out);
        $this->assertStringContainsString('[Agota: add one line of your own story', $out, 'team notes are listed');

        $en = $this->course();
        $lt = $this->course('sunu-sveikatos-kursas');
        $this->assertFalse($en->isPublished());
        $this->assertTrue($en->isFree());
        $this->assertSame(['en', 'lt'], [$en->getLocale(), $lt->getLocale()]);
        $this->assertCount(30, $en->getLessons());
        $this->assertSame('The Poop Review', $en->getLessons()->first()->getTitle());
        $this->assertSame('Išmatų apžvalga', $lt->getLessons()->first()->getTitle());
        $uk = $this->course('kurs-zdorovia-sobak');
        $pl = $this->course('kurs-zdrowia-psa');
        $this->assertSame(['uk', 'pl'], [$uk->getLocale(), $pl->getLocale()]);
        $this->assertCount(30, $uk->getLessons());
        $this->assertCount(30, $pl->getLessons());
        $this->assertFalse($uk->isPublished());
        // Same nine screens as the English lesson, with their own ids.
        $enSlides = $en->getLessons()->first()->getSlides();
        $ukSlides = $uk->getLessons()->first()->getSlides();
        $this->assertSame(array_column($enSlides, 'layout'), array_column($ukSlides, 'layout'));
        $this->assertEmpty(array_intersect(array_column($enSlides, 'id'), array_column($ukSlides, 'id')));
        $this->assertMatchesRegularExpression('/\p{Cyrillic}/u', $uk->getLessons()->first()->getTitle());
        $this->assertNotSame('The Poop Review', $pl->getLessons()->first()->getTitle());

        // A studio edit survives a plain re-run; --force restores the document text.
        $first = $en->getLessons()->first()->setTitle('Edited in the studio');
        $this->em->flush();
        $this->assertStringContainsString('30 left as they were', $this->import()->getDisplay());
        $this->assertSame('Edited in the studio', $this->em->find(Lesson::class, $first->getId())->getTitle());
        $this->assertStringContainsString('Courses already exist', $this->import(['--if-empty' => true])->getDisplay());
        $this->assertStringContainsString('nothing imported', $this->import(['--missing' => true])->getDisplay());
        // --missing (what each deploy runs) adds only a language that has no course yet.
        $this->em->clear();
        $this->em->remove($this->course('kurs-zdrowia-psa'));
        $this->em->flush();
        $out = $this->import(['--missing' => true])->getDisplay();
        $this->assertStringContainsString('Kurs zdrowia psa ZENOO: 30 lessons created', $out);
        $this->assertStringNotContainsString('ZENOO Dog Wellness Course:', $out);
        $this->import(['--force' => true, '--publish' => true]);
        $this->assertSame('The Poop Review', $this->em->find(Lesson::class, $first->getId())->getTitle());
        $this->assertTrue($this->course()->isPublished());
    }

    public function testCoursesNeedALoginAndDraftsAreHiddenFromPetParents(): void
    {
        $this->import();
        $lesson = $this->course()->getLessons()->first();

        $this->client->request('GET', '/courses');
        $this->assertResponseRedirects('/login');

        $this->createUser();
        $this->loginViaForm('maria@example.com', 'Password123');
        $this->client->request('GET', '/courses');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.cl-empty', 'Courses are on their way');
        $this->client->request('GET', '/courses/dog-wellness/lesson/' . $lesson->getId());
        $this->assertResponseStatusCodeSame(404);
    }

    public function testTakingALessonSavesAnswersAndCompletesIt(): void
    {
        $this->import();
        $this->publish();
        $lesson = $this->course()->getLessons()->first();
        $slides = array_column($lesson->getSlides(), null, 'layout');
        $this->createUser();
        $this->loginViaForm('maria@example.com', 'Password123');

        $crawler = $this->client->request('GET', '/courses');
        $this->assertSelectorTextContains('.cl-course__title', 'ZENOO Dog Wellness Course');
        $this->assertSelectorTextContains('.cl-continue', 'The Poop Review');
        $this->assertSame(30, $crawler->filter('.cl-lesson')->count());

        $crawler = $this->client->request('GET', '/courses/dog-wellness/lesson/' . $lesson->getId());
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.lp-slide--hook .lp-h1', 'The most honest health report');
        $this->assertSelectorExists('.lp-slide--deeper .lp-table table');
        $this->assertSelectorExists('.lp-slide--vet .lp-card--vet');
        $this->assertSame(9, $crawler->filter('.lp-slide')->count());
        $csrf = $crawler->filter('.lp-shell')->attr('data-lesson-player-csrf-value');

        $post = fn (array $data, string $token) => $this->client->request('POST', '/courses/progress/' . $lesson->getId(), [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], json_encode($data));

        $post(['slide' => 4], 'wrong');
        $this->assertResponseStatusCodeSame(400);

        $post(['slide' => 4, 'answers' => [
            $slides['write']['id'] => ['text' => [0 => 'Score: 4. Colour: light brown. Food: chicken and rice.']],
            $slides['dog']['id'] => ['done' => true],
            's_notreal' => ['text' => ['ignored']],
        ]], $csrf);
        $this->assertResponseIsSuccessful();

        $post(['slide' => 8, 'complete' => true], $csrf);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($data['completed']);
        $this->assertSame(1, $data['streak']);
        $this->assertSame(1, $data['number']);
        $this->assertStringEndsWith('/courses/dog-wellness/lesson/' . ($lesson->getId() + 1), $data['next']);

        $progress = $this->em->getRepository(LessonProgress::class)->findOneBy([]);
        $this->assertSame('Score: 4. Colour: light brown. Food: chicken and rice.', $progress->getAnswer($slides['write']['id'])['text'][0]);
        $this->assertTrue($progress->getAnswer($slides['dog']['id'])['done']);
        $this->assertArrayNotHasKey('s_notreal', $progress->getAnswers());

        $this->client->request('GET', '/courses');
        $this->assertSelectorExists('.cl-lesson.is-done');
        $this->assertSelectorTextContains('.cl-continue', 'What Your Dog Really Needs');

        // The chat coach knows the course and what Maria wrote.
        FakeClaude::$reply = 'Score 4 is a bit soft.';
        $this->client->request('POST', '/chat/send', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->chatToken()],
            json_encode(['message' => 'Is my poop log ok?']));
        $system = FakeClaude::$requests[0]['system'];
        $this->assertStringContainsString('### Lesson 1: The Poop Review', $system[0]['text']);
        $this->assertStringContainsString('Lesson 30: 3-Ingredient Snack Recipes', $system[0]['text']);
        $this->assertStringNotContainsString('Maria', $system[0]['text'], 'the cached block is the same for everyone');
        $this->assertStringContainsString('Lessons they have finished: The Poop Review', $system[1]['text']);
        $this->assertStringContainsString('Score: 4. Colour: light brown', $system[1]['text']);

        // Deleting the account removes the course progress too.
        $crawler = $this->client->request('GET', '/account');
        $this->client->submit($crawler->filter('form[action="/account/delete"]')->form(['password' => 'Password123']));
        $this->em->clear();
        $this->assertSame(0, $this->em->getRepository(LessonProgress::class)->count([]));
        $this->assertSame(0, $this->em->getRepository(ChatConversation::class)->count([]));
    }

    public function testLithuanianCourseUsesLithuanianPlayerWords(): void
    {
        $this->import();
        $this->createUser('admin@example.com', 'Password123', ['ROLE_ADMIN']);
        $this->loginViaForm('admin@example.com', 'Password123');
        $lesson = $this->course('sunu-sveikatos-kursas')->getLessons()->first();

        $this->client->request('GET', '/courses/sunu-sveikatos-kursas/lesson/' . $lesson->getId());
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('html[lang=lt]');
        $this->assertSelectorTextContains('.lp-slide--hook .lp-foot .lp-btn', 'Toliau');
        $this->assertSelectorTextContains('.lp-slide--achievement .lp-mode', 'Gautas ženklelis');
    }

    public function testPaidCoursesAreLockedForPetParentsWithoutAnOrder(): void
    {
        $this->import();
        $this->publish();
        $this->course()->setIsFree(false);
        $this->em->flush();
        $lesson = $this->course()->getLessons()->first();
        $this->createUser();
        $this->loginViaForm('maria@example.com', 'Password123');

        $this->client->request('GET', '/courses');
        $this->assertSelectorTextContains('.cl-locked', 'for ZENOO customers');
        $this->client->request('GET', '/courses/dog-wellness/lesson/' . $lesson->getId());
        $this->assertResponseRedirects('/courses');
    }

    public function testStudioIsForAdminsAndSavesCleanSlides(): void
    {
        $this->import();
        $lesson = $this->course()->getLessons()->first();

        $this->createUser();
        $this->loginViaForm('maria@example.com', 'Password123');
        $this->client->request('GET', '/admin/course-studio');
        $this->assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/logout');

        $this->createUser('admin@example.com', 'Password123', ['ROLE_ADMIN']);
        $this->loginViaForm('admin@example.com', 'Password123');
        $crawler = $this->client->request('GET', '/admin/course-studio');
        $this->assertResponseIsSuccessful();
        $this->assertSame(120, $crawler->filter('.cs-lesson-link')->count(), '30 lessons in each of the four languages');

        $crawler = $this->client->request('GET', '/admin/course-studio/lesson/' . $lesson->getId());
        $this->assertResponseIsSuccessful();
        $layouts = json_decode($crawler->filter('script[data-course-editor-target=layouts]')->text(), true);
        $this->assertArrayHasKey('dog', $layouts);
        $this->assertArrayHasKey('vet', $layouts);
        $token = $crawler->filter('[data-course-editor-csrf-value]')->attr('data-course-editor-csrf-value');

        $slides = $lesson->getSlides();
        $slides[0]['fields']['headline'] = 'New headline';
        $slides[0]['fields']['evil'] = '<script>';
        $slides[] = ['layout' => 'nope', 'fields' => []];
        $this->client->request('POST', '/admin/course-studio/lesson/' . $lesson->getId() . '/save', [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token],
            json_encode(['title' => 'The Poop Review', 'isPublished' => true, 'slides' => $slides]));
        $this->assertResponseIsSuccessful();

        $this->em->clear();
        $saved = $this->em->find(Lesson::class, $lesson->getId())->getSlides();
        $this->assertCount(9, $saved, 'unknown layouts are dropped');
        $this->assertSame('New headline', $saved[0]['fields']['headline']);
        $this->assertArrayNotHasKey('evil', $saved[0]['fields']);
        $this->assertSame($lesson->getSlides()[0]['id'], $saved[0]['id'], 'slide ids are kept, so progress stays attached');

        // The phone preview renders with the real player.
        $this->client->request('POST', '/admin/course-studio/lesson/' . $lesson->getId() . '/preview', ['payload' => json_encode(['slides' => $saved, 'index' => 7])]);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.lp-slide--vet:not([hidden])');
    }

    private function chatToken(): string
    {
        $crawler = $this->client->request('GET', '/chat');
        return $crawler->filter('#chat')->attr('data-csrf');
    }
}
