<?php

namespace App\Tests\Functional;

/** Test report step 3: copy and format fixes around the quiz (M1, M2). */
final class QuizGapsTest extends AppTestCase
{
    public function testOneEnglishPossessiveRule(): void
    {
        $user = $this->createUser();
        $this->createDog($user, ['quiz_dog_name' => 'Žaibas']);
        $this->client->loginUser($user);
        $this->client->request('GET', '/dashboard');
        $this->assertSelectorTextContains('.today-log h2', 'Žaibas’ log');
        $this->assertStringNotContainsString('Žaibas’s', $this->client->getResponse()->getContent());

        // Names without a final s keep ’s, and other languages are untouched.
        $max = $this->createDog($user, ['quiz_dog_name' => 'Max']);
        $user->setActiveDogId($max->getId());
        $this->em->flush();
        $this->client->request('GET', '/dashboard');
        $this->assertSelectorTextContains('.today-log h2', 'Max’s log');
    }

    public function testPuppyAgesInWeeksAndMonths(): void
    {
        $user = $this->createUser();
        $this->createDog($user, ['quiz_dog_name' => 'Pip', 'quiz_age_years' => 0.2, 'quiz_life_stage' => 'puppy']);
        $this->client->loginUser($user);
        $this->client->request('GET', '/dashboard/dog');
        $this->assertSelectorTextContains('.dash-facts', '10 weeks');
        $this->assertStringNotContainsString('0.2 years', $this->client->getResponse()->getContent());

        $ext = static::getContainer()->get(\App\Twig\LocaleExtension::class);
        $this->assertSame(['1 week', '6 months', '1 year', '3.5 years', '5 mėn.', '3,5 m.'], [
            $ext->dogAge(0.02, 'en'), $ext->dogAge(0.5, 'en'), $ext->dogAge(1.1, 'en'), $ext->dogAge(3.4, 'en'), $ext->dogAge(0.4, 'lt'), $ext->dogAge(3.5, 'lt'),
        ]);
    }

    public function testTheQuizHasNoGreyedOutNextAndNoChangingTotal(): void
    {
        $js = file_get_contents(static::getContainer()->getParameter('kernel.project_dir') . '/public/quiz/app.js');
        $this->assertStringNotContainsString('btn.disabled = !s.valid()', $js);
        $this->assertStringNotContainsString('questionOf', $js);
        foreach (['en', 'lt', 'uk', 'pl'] as $l) {
            $content = file_get_contents(static::getContainer()->getParameter('kernel.project_dir') . "/public/quiz/content.$l.js");
            foreach (['partOf:', 'why: {', 'editAnswers:', 'puppyGrowth:', 'keep: {', 'chooseUpdate:'] as $key) {
                $this->assertStringContainsString($key, $content, "$l: $key");
            }
        }
    }
}
