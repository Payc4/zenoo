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
        $this->assertSelectorTextContains('.today-log h2', 'Bella’s log');
        $this->assertSelectorTextContains('.today-tile', '31 kg'); // the quiz weight
        // One sheet for all four logs; nothing pre-selected.
        $this->assertCount(4, $crawler->filter('#log-sheet [data-log-panel]'));
        $this->assertCount(0, $crawler->filter('#log-sheet input[type=radio][checked]'));
        $this->assertCount(5, $crawler->filter('#log-sheet .log-shape svg'), 'body shape as pictures');

        $this->client->submit($crawler->filter('form[data-log-panel=weight]')->form(['value' => '30,4']));
        $this->client->followRedirect();
        $this->assertSame(30.4, $this->dog()->latestLog('weight')['v']);
        $this->assertSelectorTextContains('.today-tile.is-new', 'Saved just now');

        // Undo puts back what was there (today's quiz weight).
        $this->client->submit($this->client->getCrawler()->filter('.today-undo')->form());
        $this->assertSame(31.0, (float) $this->dog()->latestLog('weight')['v']);
        $crawler = $this->client->request('GET', '/dashboard');
        $this->client->submit($crawler->filter('form[data-log-panel=weight]')->form(['value' => '30,4']));

        $crawler = $this->client->request('GET', '/dashboard');
        $this->client->submit($crawler->filter('form[data-log-panel=stool]')->form(['value' => '5']));
        $this->assertSame(5, $this->dog()->latestLog('stool')['v']);
        $crawler = $this->client->request('GET', '/dashboard');
        $this->client->submit($crawler->filter('form[data-log-panel=weight]')->form(['value' => '300']));
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
        $dog->setFood('Orijen', 'Original', 'dry', '', 'chat', new \DateTimeImmutable());
        $this->em->flush();
        $this->assertStringNotContainsString('monthly check', $this->client->request('GET', '/dashboard')->text());

        (new \ReflectionProperty(DogProfile::class, 'updatedAt'))->setValue($dog, new \DateTimeImmutable('-31 days'));
        $this->em->flush();
        $this->client->request('GET', '/dashboard');
        $this->assertSelectorTextContains('.today-next', 'Time for Bella’s monthly check');
        $this->assertSelectorExists('.today-next a[href="/quiz/?dog=' . $dog->getId() . '"]');

        $dog = $this->dog();
        $dog->fillFromQuiz(QuizAccountTest::result(['quiz_bowl_score' => 61]), 'en');
        $this->em->flush();
        $this->client->request('GET', '/dashboard/dog');
        $this->assertSame('52 → 61', $this->client->getCrawler()->filter('.dog-score__chart')->attr('aria-label'));
    }

    public function testTheNextStepFollowsTheDog(): void
    {
        $user = $this->createUser('jonas@example.com');
        $this->createDog($user); // no food known yet
        $this->client->loginUser($user);
        $this->client->request('GET', '/dashboard');
        $this->assertSelectorTextContains('.today-next', 'Show the coach what Bella eats');
        $orange = $this->client->getCrawler()->filter('main .btn:not(.btn-outline):not(.btn-ghost-light)')->reduce(fn ($n) => $n->closest('dialog') === null);
        $this->assertCount(1, $orange, 'one orange button on the screen (the sheets have their own)');

        $dog = $this->dog();
        $dog->setFood('Orijen', 'Original', 'dry', '', 'chat', new \DateTimeImmutable());
        // The last weight was 15 days ago.
        $data = new \ReflectionProperty(DogProfile::class, 'data');
        $d = $data->getValue($dog);
        $d['log'] = [['d' => (new \DateTimeImmutable('-15 days'))->format('Y-m-d'), 't' => 'weight', 'v' => 31, 'src' => 'owner']];
        $data->setValue($dog, $d);
        $this->em->flush();
        $this->client->request('GET', '/dashboard');
        $this->assertSelectorTextContains('.today-next', 'Time to weigh Bella');
        $this->assertSelectorExists('.today-next button[data-log-open="weight"]');
    }
}
