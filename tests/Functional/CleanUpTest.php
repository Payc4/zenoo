<?php

namespace App\Tests\Functional;

use App\Entity\Course;
use App\Entity\DogProfile;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Test report step 8: N6, N9, N10, M5, M8 to M11, U13. */
final class CleanUpTest extends AppTestCase
{
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function testSecurityHeadersAndCspReports(): void
    {
        $this->client->request('GET', '/');
        $csp = (string) $this->client->getResponse()->headers->get('Content-Security-Policy-Report-Only');
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("font-src 'self'", $csp, 'no font host: fonts are served from the site (M8)');
        $this->assertFalse($this->client->getResponse()->headers->has('Strict-Transport-Security'), 'HSTS only over HTTPS');

        $this->client->request('GET', '/', [], [], ['HTTPS' => 'on']);
        $this->assertStringStartsWith('max-age=31536000', (string) $this->client->getResponse()->headers->get('Strict-Transport-Security'));

        $this->client->request('POST', '/csp-report', [], [], ['CONTENT_TYPE' => 'application/csp-report'],
            json_encode(['csp-report' => ['document-uri' => 'https://app.zenoo.lt/quiz/', 'violated-directive' => 'img-src', 'blocked-uri' => 'https://x.example']]));
        $this->assertResponseStatusCodeSame(204);
    }

    public function testTheQuizLoadsNoFontsFromGoogle(): void
    {
        $dir = static::getContainer()->getParameter('kernel.project_dir');
        $css = file_get_contents($dir . '/public/quiz/styles.css');
        $this->assertStringNotContainsString('fonts.googleapis', $css);
        preg_match_all("~url\('(fonts/[^']+)'\)~", $css, $m);
        $this->assertNotEmpty($m[1]);
        foreach ($m[1] as $font) {
            $this->assertFileExists($dir . '/public/quiz/' . $font);
        }
        $this->assertStringContainsString('cyrillic', $css, 'Ukrainian gets the same font');
        $this->client->request('GET', '/quiz/');
        $this->assertStringNotContainsString('fonts.g', $this->client->getResponse()->getContent());
    }

    public function testDogPhotosAreOnlyServedToTheOwnerAndOnTheSharePage(): void
    {
        $user = $this->createUser();
        $this->createDog($user);
        $this->createPetParent('other@example.com');
        $this->loginViaForm('maria@example.com', 'Password123');

        $crawler = $this->client->request('GET', '/dashboard/dog');
        $tmp = tempnam(sys_get_temp_dir(), 'dog') . '.png';
        $im = imagecreatetruecolor(300, 200);
        imagepng($im, $tmp);
        $form = $crawler->filter('form[data-photo-form]')->form();
        $form['photo']->upload($tmp);
        $this->client->submit($form);
        $this->assertResponseRedirects('/dashboard/dog');

        $this->em->clear();
        $dog = $this->em->getRepository(DogProfile::class)->findOneBy(['name' => 'Bella']);
        $name = $dog->getPhoto();
        $dir = static::getContainer()->getParameter('kernel.project_dir') . '/public/uploads';
        $this->cleanup[] = "$dir/private/dogs/$name";
        $this->assertFileExists("$dir/private/dogs/$name", 'stored where nginx refuses to serve');
        $this->assertFileDoesNotExist("$dir/dogs/$name");

        $crawler = $this->client->request('GET', '/dashboard/dog');
        $this->assertSame('/dashboard/dog-photo/' . $name, $crawler->filter('img[src*="dog-photo"]')->first()->attr('src'));
        $this->client->request('GET', '/dashboard/dog-photo/' . $name);
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'image/jpeg');
        $this->assertStringContainsString('private', (string) $this->client->getResponse()->headers->get('Cache-Control'));

        // Someone else, or nobody, gets nothing.
        $this->client->request('GET', '/logout');
        $this->client->request('GET', '/dashboard/dog-photo/' . $name);
        $this->assertResponseRedirects();
        $this->loginViaForm('other@example.com', 'Password123');
        $this->client->request('GET', '/dashboard/dog-photo/' . $name);
        $this->assertResponseStatusCodeSame(404);

        // While sharing is on, the share page shows it through the share link.
        $this->em->clear();
        $dog = $this->em->getRepository(DogProfile::class)->findOneBy(['name' => 'Bella']);
        $dog->startSharing();
        $this->em->flush();
        $code = $dog->getShareCode();
        $crawler = $this->client->request('GET', '/s/' . $code);
        $this->assertSame('/s/' . $code . '/photo.jpg', $crawler->filter('.share-card__photo')->attr('src'));
        $this->client->request('GET', '/s/' . $code . '/photo.jpg');
        $this->assertResponseIsSuccessful();
        $dog = $this->em->getRepository(DogProfile::class)->findOneBy(['name' => 'Bella']);
        $dog->stopSharing();
        $this->em->flush();
        $this->client->request('GET', '/s/' . $code . '/photo.jpg');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testAPhotoSavedBeforeMovesOutOfThePublicFolder(): void
    {
        $user = $this->createUser();
        $dog = $this->createDog($user);
        $name = sprintf('dog_%d_%s.jpg', $dog->getId(), 'abcdef0123456789');
        $dir = static::getContainer()->getParameter('kernel.project_dir') . '/public/uploads';
        @mkdir("$dir/dogs", 0775, true);
        imagejpeg(imagecreatetruecolor(20, 20), "$dir/dogs/$name");
        $this->cleanup[] = "$dir/dogs/$name";
        $this->cleanup[] = "$dir/private/dogs/$name";
        $dog->setPhoto($name);
        $this->em->flush();

        $this->loginViaForm('maria@example.com', 'Password123');
        $this->client->request('GET', '/dashboard/dog-photo/' . $name);
        $this->assertResponseIsSuccessful();
        $this->assertFileDoesNotExist("$dir/dogs/$name");
        $this->assertFileExists("$dir/private/dogs/$name");
    }

    public function testEmailSettingsAreTwoLabelledSwitches(): void
    {
        $this->createPetParent();
        $this->loginViaForm('maria@example.com', 'Password123');
        $crawler = $this->client->request('GET', '/account');
        $switches = $crawler->filter('#coach-emails button[role="switch"]');
        $this->assertCount(2, $switches);
        $this->assertSelectorTextNotContains('#coach-emails', 'Switch them off');
        foreach ($switches as $switch) {
            $label = $crawler->filter('#' . $switch->getAttribute('aria-labelledby'));
            $this->assertNotSame('', trim($label->text()));
        }
        $before = $switches->first()->attr('aria-checked');
        $this->client->submit($switches->first()->form());
        $crawler = $this->client->followRedirect();
        $this->assertNotSame($before, $crawler->filter('#coach-emails button[role="switch"]')->first()->attr('aria-checked'));
    }

    public function testDatesLookTheSameEverywhereInOneLanguage(): void
    {
        $ext = static::getContainer()->get(\App\Twig\LocaleExtension::class);
        $d = new \DateTimeImmutable('2026-11-20 10:00');
        $this->assertSame($ext->format($d, 'day', 'en'), $ext->format($d, 'long', 'en'));
        $this->assertSame('20 Nov 2026', $ext->format($d, 'day', 'en'));
        $this->assertStringContainsString('2026', $ext->format($d, 'day', 'lt'));
    }

    public function testAStoolScoreWrittenInTheLessonGoesToTheTracker(): void
    {
        $tester = new CommandTester((new Application(static::$kernel))->find('app:course:import'));
        $tester->execute([]);
        $this->em->clear();
        $course = $this->em->getRepository(Course::class)->findOneBy(['slug' => 'dog-wellness']);
        $course->setIsPublished(true);
        $this->em->flush();
        $lesson = $course->getLessons()->first();
        $write = array_column($lesson->getSlides(), null, 'layout')['write'];
        $this->assertMatchesRegularExpression('/1\s*[–-]\s*7/u', $write['fields']['prompts'][0]['instructions']);

        $user = $this->createUser();
        $this->createDog($user);
        $this->loginViaForm('maria@example.com', 'Password123');
        $crawler = $this->client->request('GET', '/courses/dog-wellness/lesson/' . $lesson->getId());
        $csrf = $crawler->filter('.lp-shell')->attr('data-lesson-player-csrf-value');
        $this->client->request('POST', '/courses/progress/' . $lesson->getId(), [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf],
            json_encode(['slide' => 4, 'answers' => [$write['id'] => ['text' => [0 => 'Score: 3, colour brown, ate 250 g']]]]));
        $this->assertResponseIsSuccessful();

        $this->em->clear();
        $dog = $this->em->getRepository(DogProfile::class)->findOneBy(['name' => 'Bella']);
        $this->assertSame(3.0, (float) $dog->latestLog('stool')['v']);
    }

    public function testAResultTakenInLithuanianReadsInEnglish(): void
    {
        $user = $this->createUser();
        $dog = $this->createDog($user, ['quiz_score_title' => 'Lietuviškas pavadinimas', 'quiz_top_fix_1' => array_key_first($this->codes()['en']['fixes']), 'quiz_fix_1_text' => 'Lietuviškai'], 'lt');
        $ext = static::getContainer()->get(\App\Twig\QuizTextExtension::class);
        static::getContainer()->get('translator')->setLocale('en');
        $this->assertNotSame('Lietuviškas pavadinimas', $ext->text($dog, 'score_title'));
        $this->assertNotSame('Lietuviškai', $ext->text($dog, 'fix_1_text'));
        static::getContainer()->get('translator')->setLocale('lt');
        $this->assertSame('Lietuviškai', $ext->text($dog, 'fix_1_text'));
    }

    private function codes(): array
    {
        return json_decode(file_get_contents(static::getContainer()->getParameter('kernel.project_dir') . '/config/quiz_codes.json'), true);
    }
}
