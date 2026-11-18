<?php

namespace App\Tests\Functional;

use App\Chat\Calculators;
use App\Entity\DogProfile;
use App\Tests\Support\FakeClaude;

/** Roadmap 05: weight, body shape, stool and itch trackers; monthly re-score. */
final class TrackersTest extends AppTestCase
{
    private function bella(): DogProfile
    {
        $user = $this->createUser();
        $dog = (new DogProfile($user))->fillFromQuiz(QuizAccountTest::result(['quiz_ideal_weight_kg' => 25.8]), 'en');
        $dog->setFood('Orijen', 'Original', 'dry', '', 'chat', new \DateTimeImmutable());
        $this->em->persist($dog);
        $this->em->flush();
        $this->client->loginUser($user);
        return $dog;
    }

    private function dog(): DogProfile
    {
        $this->em->clear();
        return $this->em->getRepository(DogProfile::class)->findOneBy([]);
    }

    public function testOwnersLogValuesAndSeeThem(): void
    {
        $this->bella();
        $crawler = $this->client->request('GET', '/dashboard');
        $this->assertSelectorTextContains('#trackers-h', 'Bella’s trackers');
        $this->assertSelectorTextContains('.dash-tracker', '31'); // the quiz weight

        $form = $crawler->filter('.dash-tracker__add')->first()->form(['value' => '30,4']);
        $this->client->submit($form);
        $this->client->followRedirect();
        $this->assertSame(30.4, $this->dog()->latestLog('weight')['v']);

        $crawler = $this->client->request('GET', '/dashboard');
        $this->client->submit($crawler->filter('.dash-tracker__add')->eq(2)->form(['value' => '5'])); // stool
        $this->assertSame(5, $this->dog()->latestLog('stool')['v']);
        $crawler = $this->client->request('GET', '/dashboard');
        $this->client->submit($crawler->filter('.dash-tracker__add')->first()->form(['value' => '300']));
        $this->client->followRedirect();
        $this->assertSelectorTextContains('body', 'doesn’t look right');

        // The newer weight feeds the maths, but the ideal weight stays the quiz's.
        $facts = Calculators::dogFacts($this->dog());
        $this->assertSame(30.4, $facts['weight_kg']);
        $this->assertSame(25.8, $facts['ideal_kg']);

        // And the coach sees the diary.
        $csrf = $this->client->request('GET', '/chat')->filter('#chat')->attr('data-csrf');
        $this->client->request('POST', '/chat/send', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf], json_encode(['message' => 'How is she doing?']));
        $profile = FakeClaude::$requests[array_key_last(FakeClaude::$requests)]['system'][1]['text'];
        $this->assertStringContainsString('stool score (1–7, 2 ideal): ' . date('Y-m-d') . ' 5', $profile);
        $this->assertStringContainsString('weight (kg): ' . date('Y-m-d') . ' 30.4', $profile);
    }

    public function testAMonthLaterTheReScoreShowsProgress(): void
    {
        $dog = $this->bella();
        $this->client->request('GET', '/dashboard');
        $this->assertSelectorNotExists('.dash-rescore');

        (new \ReflectionProperty(DogProfile::class, 'updatedAt'))->setValue($dog, new \DateTimeImmutable('-31 days'));
        $this->em->flush();
        $this->client->request('GET', '/dashboard');
        $this->assertSelectorTextContains('.dash-rescore', 'Time for Bella’s monthly check');

        $dog = $this->dog();
        $dog->fillFromQuiz(QuizAccountTest::result(['quiz_bowl_score' => 61]), 'en');
        $this->em->flush();
        $this->client->request('GET', '/dashboard');
        $this->assertSelectorTextContains('#rescore-h', 'Bella went from 52 to 61');
    }
}
