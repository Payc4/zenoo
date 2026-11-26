<?php

namespace App\Tests\Functional;

use App\Entity\QuizSubmission;
use App\Entity\User;
use App\Tests\Support\FakeStripe;

/** The free trial: the score is free, the rest opens with a month's trial through Stripe. */
final class TrialTest extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SERVER['SUBSCRIPTION_PRICE_EUR'] = $_ENV['SUBSCRIPTION_PRICE_EUR'] = '9.99';
        $_SERVER['TRIAL_BADGE_UNTIL'] = $_ENV['TRIAL_BADGE_UNTIL'] = '2099-12-31';
    }

    protected function tearDown(): void
    {
        unset($_SERVER['SUBSCRIPTION_PRICE_EUR'], $_ENV['SUBSCRIPTION_PRICE_EUR'], $_SERVER['TRIAL_BADGE_UNTIL'], $_ENV['TRIAL_BADGE_UNTIL'], $_SERVER['PAYWALL'], $_ENV['PAYWALL']);
        parent::tearDown();
    }

    private function member(): User
    {
        $user = $this->createUser();
        $this->createDog($user);
        $this->em->flush();
        $this->client->loginUser($user);
        return $user;
    }

    private function subscription(string $status, string $userId = '', int $end = 0): array
    {
        return FakeStripe::$subscriptions['sub_test_1'] = [
            'id' => 'sub_test_1', 'object' => 'subscription', 'status' => $status, 'customer' => 'cus_test_1',
            'trial_end' => $end ?: time() + 30 * 86400, 'current_period_end' => $end ?: time() + 30 * 86400,
            'metadata' => $userId !== '' ? ['user_id' => $userId] : [],
        ];
    }

    public function testWithoutAPriceThePaywallStaysOff(): void
    {
        unset($_SERVER['SUBSCRIPTION_PRICE_EUR'], $_ENV['SUBSCRIPTION_PRICE_EUR']);
        $this->member();
        $this->client->request('GET', '/dashboard');
        $this->assertResponseIsSuccessful();
    }

    public function testAMemberWithoutATrialSeesTheScoreAndTheOffer(): void
    {
        $this->member();
        foreach (['/dashboard', '/dashboard/dog', '/chat', '/courses'] as $path) {
            $this->client->request('GET', $path);
            $this->assertResponseRedirects('/trial', null, $path);
        }
        $this->client->request('GET', '/trial');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.today-score__ring', '52');
        $this->assertSelectorTextContains('.trial-badge', 'Cyber Monday Special');
        $this->assertSelectorTextContains('.trial-terms', 'Free for 30 days, then €9.99 a month.');

        // The quiz, the account and logging out stay open; the coach answers XHR with 402.
        $this->client->request('GET', '/account');
        $this->assertResponseIsSuccessful();
        $this->client->request('POST', '/chat/send', [], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        $this->assertResponseStatusCodeSame(402);
    }

    public function testStartingTheTrialOpensTheApp(): void
    {
        $user = $this->member();
        $crawler = $this->client->request('GET', '/trial');
        $this->client->submit($crawler->selectButton('Start free trial')->form());
        $this->assertResponseRedirects();
        $this->assertStringStartsWith('https://checkout.stripe.com/', $this->client->getResponse()->headers->get('Location'));
        $p = FakeStripe::lastCheckoutParams();
        $this->assertSame('subscription', $p['mode']);
        $this->assertSame('30', $p['subscription_data']['trial_period_days']);
        $this->assertSame('999', $p['line_items'][0]['price_data']['unit_amount']);
        $this->assertSame('month', $p['line_items'][0]['price_data']['recurring']['interval']);
        $this->assertSame((string) $user->getId(), $p['metadata']['user_id']);
        $this->assertSame('maria@example.com', $p['customer_email']);

        FakeStripe::$sessions['cs_test_trial'] = ['id' => 'cs_test_trial', 'mode' => 'subscription', 'status' => 'complete', 'subscription' => 'sub_test_1', 'metadata' => ['user_id' => (string) $user->getId()]];
        $this->subscription('trialing', (string) $user->getId());
        $this->client->request('GET', '/trial/done?session_id=cs_test_trial');
        $this->assertResponseRedirects('/dashboard');
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Your free month has started');

        // Account: the plan and Stripe's billing page.
        $crawler = $this->client->request('GET', '/account');
        $this->assertSelectorTextContains('#plan', 'Free month until');
        $this->client->submit($crawler->selectButton('Manage billing')->form());
        $this->assertResponseRedirects('https://billing.stripe.com/p/session/test_1');
    }

    public function testAQuizResultGoesStraightToTheTrialAndBecomesAnAccount(): void
    {
        $this->client->request('GET', '/quiz/');
        preg_match('/window\.ZQ_CONFIG = (\{.*?\});/s', $this->client->getResponse()->getContent(), $m);
        $cfg = json_decode($m[1], true);
        $this->assertSame('Start free trial', $cfg['trial']['start']);

        $this->client->request('POST', '/quiz/submit', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $cfg['accountToken']], json_encode([
            'email' => 'new.owner@example.com', 'first_name' => 'Ona', 'locale' => 'en', 'properties' => QuizAccountTest::result(), 'answers' => ['name' => 'Bella'],
        ]));
        $res = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('sent', $res['status']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $res['trial']);

        $this->client->request('POST', '/trial/start', ['_token' => $cfg['trial']['csrf'], 'quiz' => $res['trial']]);
        $this->assertStringStartsWith('https://checkout.stripe.com/', (string) $this->client->getResponse()->headers->get('Location'));
        $p = FakeStripe::lastCheckoutParams();
        $this->assertSame('new.owner@example.com', $p['customer_email']);
        $this->assertSame($res['trial'], $p['metadata']['quiz']);

        FakeStripe::$sessions['cs_test_quiz'] = ['id' => 'cs_test_quiz', 'mode' => 'subscription', 'status' => 'complete', 'subscription' => 'sub_test_1', 'metadata' => ['quiz' => $res['trial']]];
        $this->subscription('trialing');
        $this->client->request('GET', '/trial/done?session_id=cs_test_quiz');
        $this->assertResponseRedirects('/dashboard');
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'new.owner@example.com']);
        $this->assertSame('trialing', $user->getSubscriptionStatus());
        $this->assertSame('cus_test_1', $user->getStripeCustomerId());
    }

    public function testSomeoneElsesEmailCannotBeUsedToGetIntoTheirAccount(): void
    {
        $this->createUser('owner@example.com');
        $submission = new QuizSubmission('owner@example.com', 'X', 'en', QuizAccountTest::result(), str_repeat('a', 64), 'ip');
        $this->em->persist($submission);
        $this->em->flush();
        $this->client->request('GET', '/quiz/');
        preg_match('/window\.ZQ_CONFIG = (\{.*?\});/s', $this->client->getResponse()->getContent(), $m);
        $csrf = json_decode($m[1], true)['trial']['csrf'];

        $this->client->request('POST', '/trial/start', ['_token' => $csrf, 'quiz' => str_repeat('a', 64)]);
        $this->assertResponseRedirects('/login');
        $this->assertSame([], FakeStripe::$requests);

        // Even with a finished checkout for it, coming back never signs in to the existing account.
        FakeStripe::$sessions['cs_test_x'] = ['id' => 'cs_test_x', 'mode' => 'subscription', 'status' => 'complete', 'subscription' => 'sub_test_1', 'metadata' => ['quiz' => str_repeat('a', 64)]];
        $this->subscription('trialing');
        $this->client->request('GET', '/trial/done?session_id=cs_test_x');
        $this->assertResponseRedirects('/login');
    }

    public function testStripeKeepsTheAccountInStep(): void
    {
        $user = $this->member();
        $user->setSubscription('trialing', 'sub_test_1', 'cus_test_1', new \DateTimeImmutable('+20 days'));
        $this->em->flush();
        $this->client->request('GET', '/dashboard');
        $this->assertResponseIsSuccessful();

        // Cancelled, and the period is over: back to the offer.
        $this->signedWebhook(['id' => 'evt_1', 'type' => 'customer.subscription.deleted', 'data' => ['object' => $this->subscription('canceled', '', time() - 60)]]);
        $this->assertResponseIsSuccessful();
        $this->client->request('GET', '/dashboard');
        $this->assertResponseRedirects('/trial');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('h1', 'Your free month has ended');
    }

    public function testAdminsAndTheSwitchOff(): void
    {
        $admin = $this->createUser('boss@example.com', 'Password123', ['ROLE_ADMIN']);
        $this->createDog($admin);
        $this->em->flush();
        $this->client->loginUser($admin);
        $this->client->request('GET', '/dashboard');
        $this->assertResponseIsSuccessful();

        $_SERVER['PAYWALL'] = $_ENV['PAYWALL'] = 'off';
        $this->member();
        $this->client->request('GET', '/dashboard');
        $this->assertResponseIsSuccessful();
    }
}
