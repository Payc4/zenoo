<?php

namespace App\Tests\Functional;

use App\Entity\DogProfile;
use App\Quiz\ScoreInputs;
use App\Quiz\Scoring;
use App\Tests\Support\FakeClaude;
use App\Twig\TrackingExtension;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\RequestStack;

/** MVP round (25 Nov): the coach keeps the profile up to date, old results, plan language, and more. */
final class MvpTest extends AppTestCase
{
    private const INPUTS = [
        'diet' => ['pDm' => 28, 'fDm' => 16, 'cDm' => 45, 'kcalPer100g' => null, 'fromLabel' => false],
        'foodMain' => 'dry', 'foodTypes' => ['dry'], 'bcs' => 7, 'breedEnergy' => 4, 'level' => 2, 'stage' => 'adult',
        'appetite' => 'eats', 'signs' => [], 'weightKg' => 31, 'neutered' => true, 'ageYears' => 4, 'adultKg' => null,
        'feeds' => [['type' => 'dry', 'grams' => 380]], 'meals' => '2', 'treats' => 'few',
    ];

    /** A result saved before the quiz kept its inputs, with the fields it did save. */
    private const OLD = [
        'quiz_recommended_activity_band' => 4, 'quiz_activity_band' => 2, 'quiz_food_types' => ['dry'], 'quiz_food_main' => 'dry',
        'quiz_intake_kcal' => 1460, 'quiz_kcal_calculated' => 1130, 'quiz_treats' => 'few', 'quiz_meals_per_day' => '2',
        'quiz_appetite' => 'eats', 'quiz_signs' => [], 'quiz_protein_dm' => 28, 'quiz_carbs_dm' => 45, 'quiz_life_stage' => 'adult',
        'quiz_neutered' => true, 'quiz_age_years' => 4, 'quiz_weight_kg' => 31, 'quiz_bcs' => 7, 'quiz_sex' => 'female',
        'quiz_plan_food' => 'Dry food: about 650 g a day',
    ];

    private function dog(string $locale = 'en', array $inputs = self::INPUTS): DogProfile
    {
        $user = $this->createUser();
        $dog = $this->createDog($user, ['quiz_weight_kg' => $inputs['weightKg'], 'quiz_bcs' => $inputs['bcs']], $locale);
        $dog->setQuizAnswers(['name' => 'Bella', '_inputs' => $inputs]);
        static::getContainer()->get(\App\Quiz\ScoreRecalculator::class)->recalculate($dog, 'rules', new \DateTimeImmutable());
        $dog->dismissScoreChange();
        $this->em->flush();
        $this->client->loginUser($user);
        return $dog;
    }

    private function reload(): DogProfile
    {
        $this->em->clear();
        return $this->em->getRepository(DogProfile::class)->findOneBy([]);
    }

    private function chat(string $message): string
    {
        $csrf = $this->client->request('GET', '/chat')->filter('#chat')->attr('data-csrf');
        $this->client->request('POST', '/chat/send', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf], json_encode(['message' => $message]));
        return (string) $this->client->getInternalResponse()->getContent();
    }

    private function rescoreAll(): string
    {
        $tester = new CommandTester((new Application(static::$kernel))->find('app:dogs:rescore'));
        $tester->execute([]);
        return $tester->getDisplay();
    }

    public function testWhatTheyTellTheCoachUpdatesTheProfileScoreAndPlan(): void
    {
        $before = $this->dog()->getBowlScore();
        FakeClaude::$script = [
            ['text' => '', 'tool' => ['name' => 'update_dog_profile', 'input' => [
                'daily_food' => [['type' => 'dry', 'grams' => 300]], 'treats' => 'none',
                'activity' => ['minutes' => 3], 'symptoms_now' => ['itching'],
            ]]],
            ['text' => 'Good changes. Her score moved too.'],
        ];
        $body = $this->chat('We cut treats, she gets 300 g of dry now, walks 1.5 hours a day, but she started itching.');
        $dog = $this->reload();
        $expected = Scoring::evaluate(['feeds' => [['type' => 'dry', 'grams' => 300]], 'treats' => 'none', 'level' => 3, 'signs' => ['itching']] + self::INPUTS);
        $this->assertSame($expected['total'], $dog->getBowlScore());
        $this->assertNotSame($before, $dog->getBowlScore());
        $this->assertSame(['none', ['itching'], 3], [$dog->get('treats'), $dog->get('signs'), $dog->get('activity_band')], 'the profile shows today’s answers');
        $this->assertStringContainsString('"kind":"score"', $body);
        $this->assertStringContainsString('Bowl Score was worked out again', json_encode(FakeClaude::$requests[1]['messages']));

        $this->client->request('GET', '/dashboard');
        $this->assertSelectorTextContains('.score-change', 'Worked out again with what’s new: daily amount, treats, activity, symptoms.');

        // A retake starts afresh.
        $dog->fillFromQuiz(QuizAccountTest::result(), 'en');
        $this->assertSame([], $dog->getProgress());
    }

    public function testAFoodSavedFromALabelChangesTheBowl(): void
    {
        $this->dog();
        FakeClaude::$script = [
            ['text' => '', 'tool' => ['name' => 'save_food', 'input' => [
                'brand' => 'Acme', 'product' => 'Lamb', 'format' => 'wet', 'notes' => '',
                'analysis' => ['protein' => 10, 'fat' => 6, 'fibre' => 0.5, 'ash' => 2, 'moisture' => 78],
            ]]],
            ['text' => 'Saved Acme Lamb.'],
        ];
        $this->chat('[photo of the label]');
        $dog = $this->reload();
        $this->assertSame('wet', $dog->get('food_main'));
        $this->assertTrue($dog->get('label_given'));
        $this->assertSame(Scoring::jsRound(Scoring::fromLabel(10, 6, 0.5, 2, 78)['pDm']), (float) $dog->get('protein_dm'));
        $this->assertMatchesRegularExpression('/about \d+ g a day/i', (string) $dog->get('plan_food'), 'a daily amount for the new food');
    }

    public function testOldResultsAreRebuiltAndReScoredOnDeploy(): void
    {
        $user = $this->createUser();
        $old = $this->createDog($user, self::OLD);
        $bare = $this->createDog($user, ['quiz_dog_name' => 'Max']);
        $this->client->loginUser($user);

        $this->assertStringContainsString('1 rebuilt from old results, 1 changed, 1 without enough saved', $this->rescoreAll());
        $this->em->clear();
        $old = $this->em->find(DogProfile::class, $old->getId());
        $this->assertNotNull(ScoreInputs::fromDog($old));
        $this->assertStringStartsWith('Dry food: about 380 g a day', (string) $old->get('plan_food'), 'the old 650 g is gone');
        $this->assertSame(['rules'], $old->getScoreChange()['why']);
        $this->assertSame('Dry food: about 650 g a day', $old->getScoreChange()['plan_from']);
        $this->assertStringContainsString('0 changed', $this->rescoreAll(), 'running it again changes nothing');

        $this->em->clear();
        $user = $this->em->getRepository(\App\Entity\User::class)->find($user->getId());
        $user->setActiveDogId($old->getId());
        $this->em->flush();
        $this->client->request('GET', '/dashboard');
        $this->assertSelectorTextContains('.score-change', 'Worked out again with ZENOO’s updated rules.');

        // The bare one: a re-check, started from what it saved.
        $user->setActiveDogId($bare->getId());
        $this->em->flush();
        $this->client->request('GET', '/dashboard');
        $this->assertSelectorTextContains('.score-change', 'Re-check Max to update the score and plan');
        $crawler = $this->client->request('GET', '/quiz/?dog=' . $bare->getId());
        $this->assertStringContainsString('"weight":"31"', $crawler->html());
    }

    public function testThePlanFollowsThePageLanguageAndShowsFoodEnergyApart(): void
    {
        $this->dog('lt', ['treats' => 'lots'] + self::INPUTS);
        $this->assertStringStartsWith('Sausas maistas', (string) $this->reload()->get('plan_food'));
        $this->client->request('GET', '/dashboard/dog');
        $this->assertSelectorTextContains('.dash-plan', 'Dry food: about');
        $this->client->request('GET', '/dashboard');
        $this->assertSelectorTextContains('.today-bowl', 'kcal a day from food,');
        $this->assertSelectorTextContains('.today-bowl', 'with treats');
    }

    public function testTheSameFactInOtherWordsIsSavedOnce(): void
    {
        $dog = $this->dog();
        $now = new \DateTimeImmutable();
        $this->assertTrue($dog->addNote('health', 'Itchy ears', $now));
        $this->assertTrue($dog->addNote('health', 'Itchy ears since November.', $now), 'the more detailed one replaces it');
        $this->assertFalse($dog->addNote('health', 'itchy ears', $now));
        $this->assertTrue($dog->addNote('health', 'Eats grass on walks', $now));
        $this->assertSame(['Itchy ears since November.', 'Eats grass on walks'], array_column($dog->getNotes(), 'text'));
    }

    public function testTwoDogsWithTheSameNameAreToldApart(): void
    {
        $user = $this->createUser();
        $this->createDog($user, ['quiz_dog_name' => 'Max', 'quiz_breed' => 'Beagle']);
        $this->createDog($user, ['quiz_dog_name' => 'max', 'quiz_breed' => 'Labrador Retriever']);
        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/dashboard');
        $names = $crawler->filter('.dog-switch__menu li')->each(fn ($li) => trim($li->text()));
        $this->assertContains('Max · Beagle', array_map(fn ($n) => trim($n, ' ✓'), $names));
        $this->assertContains('max · Labrador Retriever', array_map(fn ($n) => trim($n, ' ✓'), $names));
    }

    public function testHotjarOnlyWithAValidIdAndTheWhatsNewPage(): void
    {
        $this->assertSame(1234567, (new TrackingExtension(new RequestStack(), null, null, '1234567'))->config()['hotjar']);
        $this->assertNull((new TrackingExtension(new RequestStack(), null, null, '<script>'))->config()['hotjar']);

        // What's new is for admins only: not linked, not in the sitemap, login needed.
        $this->client->request('GET', '/whats-new');
        $this->assertResponseRedirects('/login');
        $this->client->request('GET', '/privacy-policy');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('footer a[href="/whats-new"]');
        $this->client->request('GET', '/sitemap.xml');
        $this->assertStringNotContainsString('/whats-new', $this->client->getResponse()->getContent());
        $this->client->loginUser($this->createUser('member@example.com'));
        $this->client->request('GET', '/whats-new');
        $this->assertResponseStatusCodeSame(403);
        $this->client->loginUser($this->createUser('admin@example.com', 'Password123', ['ROLE_ADMIN']));
        $this->client->request('GET', '/whats-new');
        $this->assertResponseIsSuccessful();
        $this->assertAnySelectorTextContains('h2', 'The coach keeps the profile up to date');
        $this->client->request('GET', '/privacy-policy');
        $this->assertSelectorExists('footer a[href="/whats-new"]');
    }

    public function testTheResultNoLongerPromisesAFeedingGuide(): void
    {
        foreach (['en', 'lt', 'uk', 'pl'] as $l) {
            $content = file_get_contents(static::getContainer()->getParameter('kernel.project_dir') . "/public/quiz/content.$l.js");
            $this->assertDoesNotMatchRegularExpression('/feeding guide|šėrimo gid|посібник із годування|посібник ZENOO|poradnik żywienia/iu', $content, $l);
        }
    }
}
