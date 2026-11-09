<?php

namespace App\Tests\Functional;

use App\Entity\DogProfile;
use App\Entity\QuizSubmission;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Tests\Support\FakeClaude;
use App\Tests\Support\FakeStripe;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/** Quiz → account (email confirmation), the "My dog" dashboard and the chat coach's view of the dog. */
final class QuizAccountTest extends AppTestCase
{
    public static function result(array $overrides = []): array
    {
        return $overrides + [
            'quiz_dog_name' => 'Bella',
            'quiz_breed' => 'Labrador Retriever',
            'quiz_breed_photo_url' => 'https://cdn.example/zq-email-breed-labrador.jpg',
            'quiz_age_years' => 4.5,
            'quiz_life_stage' => 'adult',
            'quiz_weight_kg' => 31,
            'quiz_bcs' => 7,
            'quiz_signs' => ['stools', 'itching'],
            'quiz_food_main' => 'dry',
            'quiz_bowl_score' => 52,
            'quiz_score_band' => 'look',
            'quiz_score_title' => 'WORTH A CLOSER LOOK',
            'quiz_score_text' => 'A few simple changes could make a real difference for Bella.',
            'quiz_sub_protein' => 8, 'quiz_sub_carbs' => 6, 'quiz_sub_freshness' => 4, 'quiz_sub_body' => 8,
            'quiz_sub_activity' => 12, 'quiz_sub_signals' => 6, 'quiz_sub_routine' => 8,
            'quiz_fix_1_text' => 'Look for a named meat as the first ingredient.',
            'quiz_fix_2_text' => 'Cut the portion by about 10%.',
            'quiz_kcal_target' => 1210.4,
            'quiz_plan_food' => 'Dry food: about 300 g a day',
            'quiz_plan_meals' => '2 meals a day',
            'evil' => 'dropped',
            'quiz_note' => '<script>alert(1)</script>Hi',
        ];
    }

    private function token(): string
    {
        $crawler = $this->client->request('GET', '/quiz/');
        preg_match('/window\.ZQ_CONFIG = (\{.*?\});/s', $this->client->getResponse()->getContent(), $m);
        return json_decode($m[1], true)['accountToken'];
    }

    private function submit(array $body, ?string $token = null): array
    {
        $this->client->request('POST', '/quiz/submit', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token ?? $this->token()], json_encode($body));
        return json_decode($this->client->getResponse()->getContent(), true) ?? [];
    }

    private function confirmUrl(): string
    {
        $html = $this->getMailerMessage()->getHtmlBody();
        $this->assertMatchesRegularExpression('#/quiz/confirm/[a-f0-9]{64}#', $html);
        preg_match('#https?://[^"]+/quiz/confirm/[a-f0-9]{64}#', $html, $m);
        return $m[0];
    }

    private function importCourse(): void
    {
        (new CommandTester((new Application(static::$kernel))->find('app:course:import')))->execute(['--publish' => true]);
        $this->em->clear();
    }

    public function testQuizPageHasTheAccountSettings(): void
    {
        $this->client->request('GET', '/quiz/');
        $html = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('"accountSubmitUrl":"/quiz/submit"', $html);
        $this->assertStringContainsString('/quiz/content.en.js', $html);
        $this->assertStringContainsString('"user":null', $html);
    }

    public function testSubmitNeedsAValidTokenEmailAndResult(): void
    {
        $this->assertSame(400, (function () { $this->submit(['email' => 'a@example.com', 'properties' => self::result()], 'bad'); return $this->client->getResponse()->getStatusCode(); })());
        $token = $this->token();
        $this->submit(['email' => 'not-an-email', 'properties' => self::result()], $token);
        $this->assertResponseStatusCodeSame(400);
        $this->submit(['email' => 'a@example.com', 'properties' => ['quiz_dog_name' => 'X']], $token);
        $this->assertResponseStatusCodeSame(400, 'a result needs a score');
        $this->assertSame(0, $this->em->getRepository(QuizSubmission::class)->count([]));
    }

    public function testTheConfirmationLinkIsWrittenToTheKlaviyoProfile(): void
    {
        $data = $this->submit(['email' => 'Ona@Example.com', 'first_name' => 'Ona', 'locale' => 'en', 'properties' => self::result()]);
        $this->assertSame(['status' => 'sent'], $data);
        $this->assertCount(1, FakeStripe::$klaviyo);
        $call = FakeStripe::$klaviyo[0];
        $this->assertSame('https://a.klaviyo.com/api/profile-import/', $call['url']);
        $this->assertContains('Authorization: Klaviyo-API-Key pk_test_dummy', $call['headers']);
        $body = json_decode($call['body'], true)['data'];
        $this->assertSame('ona@example.com', $body['attributes']['email']);
        // Same personal link as in the site's own email (which still goes out by default).
        $this->assertSame($this->confirmUrl(), $body['attributes']['properties']['dashboard_link']);

        // Lithuanian quiz: no LT key, so nothing goes to Klaviyo; the site email still does.
        FakeStripe::$klaviyo = [];
        $this->submit(['email' => 'jonas@example.com', 'locale' => 'lt', 'properties' => self::result()]);
        $this->assertCount(0, FakeStripe::$klaviyo);
        $this->assertEmailCount(1);
    }

    public function testKlaviyoOnlyModeSkipsTheSiteEmailUnlessKlaviyoFails(): void
    {
        $saved = [$_SERVER['QUIZ_SITE_CONFIRM_EMAIL'] ?? null, $_ENV['QUIZ_SITE_CONFIRM_EMAIL'] ?? null];
        $_SERVER['QUIZ_SITE_CONFIRM_EMAIL'] = $_ENV['QUIZ_SITE_CONFIRM_EMAIL'] = 'false';
        try {
            $this->assertSame(['status' => 'sent'], $this->submit(['email' => 'a@example.com', 'locale' => 'en', 'properties' => self::result()]));
            $this->assertCount(1, FakeStripe::$klaviyo);
            $this->assertEmailCount(0);

            FakeStripe::$klaviyoDown = true;
            $this->assertSame(['status' => 'sent'], $this->submit(['email' => 'b@example.com', 'locale' => 'en', 'properties' => self::result()]));
            $this->assertEmailCount(1);
        } finally {
            [$_SERVER['QUIZ_SITE_CONFIRM_EMAIL'], $_ENV['QUIZ_SITE_CONFIRM_EMAIL']] = $saved;
        }
    }

    public function testConfirmingTheEmailCreatesTheAccountAndDashboard(): void
    {
        $this->importCourse();
        $data = $this->submit(['email' => ' Maria@Example.com ', 'first_name' => 'Maria', 'locale' => 'en', 'properties' => self::result()]);
        $this->assertSame(['status' => 'sent'], $data);
        $this->assertEmailCount(1);
        $email = $this->getMailerMessage();
        $this->assertEmailHeaderSame($email, 'To', 'maria@example.com');
        $this->assertEmailHeaderSame($email, 'Subject', 'Confirm your email to see Bella’s dashboard');
        $this->assertEmailHtmlBodyContains($email, 'Bella’s Bowl Score: 52/100');
        $url = $this->confirmUrl();

        $saved = $this->em->getRepository(QuizSubmission::class)->findOneBy([]);
        $this->assertArrayNotHasKey('evil', $saved->getData(), 'only quiz_* fields are kept');
        $this->assertSame('alert(1)Hi', $saved->getData()['quiz_note'], 'tags are stripped');
        $this->assertNull($this->em->getRepository(User::class)->findOneBy(['email' => 'maria@example.com']), 'no account before the click');

        $this->client->request('GET', parse_url($url, PHP_URL_PATH));
        $this->assertResponseRedirects('/dashboard');
        $user = static::getContainer()->get(UserRepository::class)->findOneByEmail('maria@example.com');
        $this->assertNotNull($user);
        $this->assertSame('Maria', $user->getFirstName());
        $this->assertFalse($user->hasPassword());

        $crawler = $this->client->followRedirect();
        $this->assertSelectorTextContains('.flash-success', 'Welcome to ZENOO! Your account is ready and Bella’s profile is saved.');
        $this->assertSelectorTextContains('.dash-head h1', 'Bella’s dashboard');
        $this->assertSelectorTextContains('.dash-score__num', '52');
        $this->assertSame(7, $crawler->filter('.dash-bars li')->count());
        $this->assertSelectorTextContains('.dash-tips', 'Look for a named meat');
        $this->assertSelectorTextContains('.dash-plan', 'about 1210 kcal a day');
        $this->assertSelectorExists('.dash-notice', 'asks for a password');
        $this->assertSelectorExists('img.dash-photo[src="/quiz/assets/photos/breed-labrador.jpg"]');
        // One lesson per concern: weight (BCS 7) first, then the loose stools, then the itching.
        $lessons = $crawler->filter('.dash-lesson h3')->each(fn ($n) => $n->text());
        $this->assertSame(['Safe Weight Loss', 'The Poop Review', 'Paw Licking, Ears & the Gut'], $lessons);
        $this->assertSelectorTextContains('.dash-lesson', 'To reach a healthy weight');
        $this->assertStringContainsString('/chat?ask=How%20much%20should%20Bella%20eat%20a%20day', $this->client->getResponse()->getContent());

        // The link works once.
        $this->client->request('GET', parse_url($url, PHP_URL_PATH));
        $this->assertResponseRedirects('/dashboard');

        // First password: no current password needed, then it's a normal account.
        $crawler = $this->client->request('GET', '/account');
        $this->assertSelectorNotExists('#currentPassword');
        $this->client->submit($crawler->filter('form[action="/account/password"]')->form(['newPassword' => 'MyNewPass123', 'confirmPassword' => 'MyNewPass123']));
        $this->em->clear();
        $this->assertTrue(static::getContainer()->get(UserRepository::class)->findOneByEmail('maria@example.com')->hasPassword());
    }

    public function testExistingAccountGetsTheProfileAndLoggedInSubmitSavesDirectly(): void
    {
        $this->createUser();
        $this->submit(['email' => 'maria@example.com', 'properties' => self::result()]);
        $this->client->request('GET', parse_url($this->confirmUrl(), PHP_URL_PATH));
        $this->assertResponseRedirects('/dashboard');
        $this->assertSame(1, $this->em->getRepository(User::class)->count([]));
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.flash-success', 'Bella’s profile is saved to your account.');
        $this->assertSelectorNotExists('.dash-notice', 'existing account keeps its password');

        // Logged in: a new quiz replaces the profile straight away, no email.
        $data = $this->submit(['email' => 'other@example.com', 'properties' => self::result(['quiz_dog_name' => 'Rex', 'quiz_bowl_score' => 81])]);
        $this->assertSame('saved', $data['status']);
        $this->assertSame('/dashboard', $data['dashboard']);
        $this->em->clear();
        $dogs = $this->em->getRepository(DogProfile::class)->findAll();
        $this->assertCount(1, $dogs);
        $this->assertSame('Rex', $dogs[0]->getName());
        $this->assertSame(81, $dogs[0]->getBowlScore());

        // The quiz page fills in the logged-in pet parent's name and email.
        $this->client->request('GET', '/quiz/');
        $this->assertStringContainsString('"user":{"firstName":"Maria","email":"maria@example.com"}', $this->client->getResponse()->getContent());
    }

    public function testTooManySubmissionsAndBadLinks(): void
    {
        $token = $this->token();
        for ($i = 0; $i < 3; $i++) {
            $this->submit(['email' => 'spam@example.com', 'properties' => self::result()], $token);
            $this->assertResponseIsSuccessful();
        }
        $this->submit(['email' => 'spam@example.com', 'properties' => self::result()], $token);
        $this->assertResponseStatusCodeSame(429);

        // Old unconfirmed results are cleared when the next quiz comes in.
        $this->em->getConnection()->executeStatement("UPDATE quiz_submission SET created_at = '2020-01-01 00:00:00'");
        $this->submit(['email' => 'new@example.com', 'properties' => self::result()], $token);
        $this->assertSame(1, $this->em->getRepository(QuizSubmission::class)->count([]));

        $this->client->request('GET', '/quiz/confirm/' . str_repeat('a', 64));
        $this->assertResponseStatusCodeSame(410);
        $this->assertSelectorTextContains('h1', 'This link doesn’t work');
    }

    public function testQuizAccountCanBeDeletedWithoutAPassword(): void
    {
        $this->submit(['email' => 'maria@example.com', 'first_name' => 'Maria', 'properties' => self::result()]);
        $this->client->request('GET', parse_url($this->confirmUrl(), PHP_URL_PATH));
        $crawler = $this->client->request('GET', '/account');
        $this->client->submit($crawler->filter('form[action="/account/delete"]')->form(['password' => 'delete']));
        $this->em->clear();
        $this->assertSame(0, $this->em->getRepository(User::class)->count([]));
        $this->assertSame(0, $this->em->getRepository(DogProfile::class)->count([]));
        $this->assertSame(0, $this->em->getRepository(QuizSubmission::class)->count([]));
    }

    public function testTheCoachKnowsTheDogAndSkipsTheStartingQuestions(): void
    {
        $this->submit(['email' => 'maria@example.com', 'first_name' => 'Maria', 'properties' => self::result()]);
        $this->client->request('GET', parse_url($this->confirmUrl(), PHP_URL_PATH));

        $crawler = $this->client->request('GET', '/chat?ask=How%20much%20should%20Bella%20eat%3F');
        $this->assertSame('How much should Bella eat?', $crawler->filter('#chat-input')->text());
        $this->assertSame(0, $crawler->filter('#chat-cards')->count(), 'no starting cards: the quiz told us about the dog');

        FakeClaude::$reply = 'About 300 g.';
        $this->client->request('POST', '/chat/send', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $crawler->filter('#chat')->attr('data-csrf')], json_encode(['message' => 'Hi']));
        $personal = FakeClaude::$requests[0]['system'][1]['text'];
        $this->assertStringContainsString('Their dog, from the Bowl Score quiz', $personal);
        $this->assertStringContainsString('- Name: Bella', $personal);
        $this->assertStringContainsString('Signs the pet parent noticed: stools, itching', $personal);
        $this->assertStringContainsString('Bowl Score: 52/100 (look)', $personal);
    }
}
