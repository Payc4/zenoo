<?php

namespace App\Tests\Functional;

use App\Entity\DogProfile;
use App\Tests\Support\FakeClaude;
use App\Tests\Support\FakeShop;
use App\Tests\Support\FakeStripe;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/** Roadmap 01 and coach rule 05: a size-aware ZENOO plan with a cart link, on the dashboard, the result and in the coach. */
final class ZenooPlanTest extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FakeStripe::$shopProducts = FakeShop::products();
        (new CommandTester((new Application(static::$kernel))->find('app:catalogue:sync')))->execute([]);
    }

    private function bella(array $quiz = []): DogProfile
    {
        $user = $this->createUser();
        $dog = (new DogProfile($user))->fillFromQuiz(QuizAccountTest::result($quiz + ['quiz_activity_band' => 3, 'quiz_neutered' => 'yes']), 'en');
        $this->em->persist($dog);
        $this->em->flush();
        $this->client->loginUser($user);
        return $dog;
    }

    public function testABigDogGetsATopperPlanWithACartLink(): void
    {
        $this->bella(); // 31 kg Labrador
        $crawler = $this->client->request('GET', '/dashboard');
        $this->assertSelectorTextContains('#zenoo-h', 'Bella’s ZENOO plan');
        $this->assertSelectorTextContains('.dash-zenoo', 'ZENOO RAW Jautiena šunims');
        // 1134 kcal × 0.95 × 25% / 172 kcal per 100 g = 157 g → 155 g, about €13 a day from the
        // 6-pack (€150 / 1800 g): over the €4 budget even as a topper, so the topper is the pick.
        $pick = $crawler->filter('.dash-zenoo__option.is-recommended');
        $this->assertStringContainsString('As a topper', $pick->text());
        $this->assertStringContainsString('155 g a day', $pick->text());

        // 14 × 157 g = 2.2 kg: eight 300 g packs (€230) cost less than two six-packs (€300).
        $this->client->click($pick->selectLink('2-week starter: €230.00')->link());
        $this->assertResponseRedirects('https://zenoo.lt/cart/91:8?utm_source=zenoo-app&utm_medium=referral&utm_campaign=zenoo-plan&utm_content=dashboard', null, 'tagged so the sale can be counted');
        $this->em->clear();
        $this->assertSame(1, $this->em->getRepository(DogProfile::class)->findOneBy([])->getPlanClicks()['topper']);
    }

    public function testTheBudgetDecidesTheShare(): void
    {
        $dog = $this->bella(['quiz_weight_kg' => 4, 'quiz_bcs' => 5]);
        $c = static::getContainer();
        $plan = fn (string $budget) => (new \App\Catalogue\PlanBuilder($c->get(\App\Repository\CatalogueProductRepository::class), $c->get(\App\Catalogue\CatalogueSync::class), $budget, 'START10'))->forDog($dog);
        $this->assertSame('full', $plan('20')['recommended']); // a 4 kg dog: about 180 g, €15 a day
        $this->assertSame('half', $plan('10')['recommended']);
        $this->assertSame('topper', $plan('4')['recommended']);
        $this->assertStringContainsString('?discount=START10&utm_source=zenoo-app', $plan('4')['options']['topper']['cart_url']);
    }

    public function testTheQuizAndTheCoachGetTheSamePlan(): void
    {
        $this->bella();
        $html = $this->client->request('GET', '/quiz/')->html();
        preg_match('/window\.ZQ_CONFIG = (\{.*?\});/s', $html, $m);
        $zenoo = json_decode($m[1], true)['zenoo'];
        $this->assertSame('ZENOO RAW Jautiena šunims', $zenoo['title']);
        $this->assertSame(['id' => '92', 'title' => '6 x 300 g', 'packGrams' => 1800, 'price' => 150], $zenoo['variant']); // best value per gram
        $this->assertCount(2, $zenoo['packs']);
        $this->assertSame(172, (int) $zenoo['kcal100']);

        FakeClaude::$script = [
            ['text' => '', 'tool' => ['name' => 'zenoo_plan', 'input' => []]],
            ['text' => 'As a topper, about 155 g a day. See [your ZENOO plan](/dashboard#zenoo-h).'],
        ];
        $csrf = $this->client->request('GET', '/chat')->filter('#chat')->attr('data-csrf');
        $this->client->request('POST', '/chat/send', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf], json_encode(['message' => 'Should I try ZENOO raw?']));
        $msgs = FakeClaude::$requests[1]['messages'];
        $result = json_decode(end($msgs)['content'][0]['content'], true);
        $this->assertSame('topper', $result['plan_for_this_dog']['recommended']);
        $this->assertSame(155, $result['plan_for_this_dog']['options']['topper']['grams_per_day']);
        $this->assertCount(2, $result['products'], 'the cat product is left out');
        $this->assertArrayNotHasKey('cart_url', $result['plan_for_this_dog']['options']['topper']);
    }

    public function testNoCatalogueNoPlan(): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM catalogue_product');
        $this->bella();
        $this->client->request('GET', '/dashboard');
        // No plan, but the coach's plan link still lands on a section that links the shop (N7).
        $this->assertSelectorNotExists('.dash-zenoo__option');
        $this->assertSelectorExists('#zenoo-h');
        $this->assertSelectorExists('.dash-zenoo a[href^="https://zenoo.lt/?utm_source=zenoo-app"]');
    }
}
