<?php

namespace App\Tests\Functional;

use App\Service\EmailService;
use App\Service\KlaviyoProfiles;
use App\Tests\Support\FakeStripe;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Site emails sent as Klaviyo events (EMAIL_DELIVERY=klaviyo) instead of SMTP. */
final class KlaviyoEmailTest extends AppTestCase
{
    private array $saved = [];

    protected function setUp(): void
    {
        $this->saved = [$_SERVER['EMAIL_DELIVERY'] ?? null, $_ENV['EMAIL_DELIVERY'] ?? null];
        $_SERVER['EMAIL_DELIVERY'] = $_ENV['EMAIL_DELIVERY'] = 'klaviyo';
        parent::setUp();
    }

    protected function tearDown(): void
    {
        [$_SERVER['EMAIL_DELIVERY'], $_ENV['EMAIL_DELIVERY']] = $this->saved;
        parent::tearDown();
    }

    /** @return array{metric: string, email: string, properties: array, profile: array} */
    private function event(int $i = -1): array
    {
        $calls = array_values(array_filter(FakeStripe::$klaviyo, fn ($c) => str_ends_with($c['url'], '/api/events/')));
        $this->assertNotEmpty($calls, 'no Klaviyo event was sent');
        $a = json_decode($calls[$i < 0 ? \count($calls) + $i : $i]['body'], true)['data']['attributes'];
        return [
            'metric' => $a['metric']['data']['attributes']['name'],
            'email' => $a['profile']['data']['attributes']['email'],
            'properties' => $a['properties'],
            'profile' => $a['profile']['data']['attributes'],
        ];
    }

    public function testRegistrationSendsTheWelcomeEventNotAnEmail(): void
    {
        $crawler = $this->client->request('GET', '/register');
        $this->client->submit($crawler->selectButton('Create account')->form([
            'registration_form[firstName]' => 'Maria',
            'registration_form[email]' => 'maria@example.com',
            'registration_form[plainPassword][first]' => 'Password123',
            'registration_form[plainPassword][second]' => 'Password123',
            'registration_form[agreeTerms]' => '1',
        ]));
        $this->assertResponseRedirects('/dashboard');
        $this->assertEmailCount(0);

        $e = $this->event();
        $this->assertSame('ZENOO Welcome', $e['metric']);
        $this->assertSame('maria@example.com', $e['email']);
        $this->assertSame('Maria', $e['profile']['first_name']);
        $this->assertSame('Welcome to ZENOO', $e['properties']['subject']);
        $this->assertSame('en', $e['properties']['locale']);
        $this->assertSame('http://localhost/dashboard', $e['properties']['dashboard_url']);
    }

    public function testPasswordResetLinkFromTheEventWorks(): void
    {
        $this->createUser();
        $crawler = $this->client->request('GET', '/forgot-password');
        $this->client->submit($crawler->selectButton('Send reset link')->form(['email' => 'maria@example.com']));
        $this->assertEmailCount(0);

        $e = $this->event();
        $this->assertSame('ZENOO Password Reset', $e['metric']);
        $this->assertMatchesRegularExpression('#^http://localhost/reset-password/[a-f0-9]{64}$#', $e['properties']['reset_link']);

        $crawler = $this->client->request('GET', $e['properties']['reset_link']);
        $this->client->submit($crawler->selectButton('Save new password')->form(['password' => 'NewPassword456', 'password_confirm' => 'NewPassword456']));
        $this->assertResponseRedirects('/login');
        $this->assertSame('ZENOO Password Changed', $this->event()['metric']);
    }

    public function testLithuanianUserGetsALithuanianEventAndTheQuizLinkGoesOutToo(): void
    {
        $this->createUser()->setLocale('lt');
        $this->em->flush();
        $crawler = $this->client->request('GET', '/forgot-password');
        $this->client->submit($crawler->selectButton('Send reset link')->form(['email' => 'maria@example.com']));
        $e = $this->event();
        $this->assertSame('lt', $e['properties']['locale']);
        $this->assertSame('lt', $e['profile']['locale']);
        $this->assertNotSame('Reset your ZENOO password', $e['properties']['subject']);

        // Lithuanian quiz: no LT list in Klaviyo, so the confirmation link goes out as an event.
        FakeStripe::$klaviyo = [];
        $this->client->request('GET', '/quiz/');
        preg_match('/window\.ZQ_CONFIG = (\{.*?\});/s', $this->client->getResponse()->getContent(), $m);
        $token = json_decode($m[1], true)['accountToken'];
        $this->client->request('POST', '/quiz/submit', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token],
            json_encode(['email' => 'ona@example.com', 'first_name' => 'Ona', 'locale' => 'lt', 'properties' => QuizAccountTest::result()]));
        $this->assertSame(['status' => 'sent'], json_decode($this->client->getResponse()->getContent(), true));
        $e = $this->event();
        $this->assertSame('ZENOO Quiz Confirmation', $e['metric']);
        $this->assertSame('Bella', $e['properties']['dog_name']);
        $this->assertSame(52, $e['properties']['bowl_score']);
        $this->assertMatchesRegularExpression('#/quiz/confirm/[a-f0-9]{64}$#', $e['properties']['confirm_link']);
    }

    public function testKlaviyoDownShowsTheSameNeutralMessage(): void
    {
        $this->createUser();
        FakeStripe::$klaviyoDown = true;
        $crawler = $this->client->request('GET', '/forgot-password');
        $this->client->submit($crawler->selectButton('Send reset link')->form(['email' => 'maria@example.com']));
        $this->assertResponseRedirects();
        $this->assertEmailCount(0);
    }

    public function testAutoPicksKlaviyoOnlyWithoutSmtpAndWithAKey(): void
    {
        $make = function (string $dsn, ?string $key): EmailService {
            $klaviyo = new KlaviyoProfiles(new MockHttpClient(), new NullLogger(), $key, null, true);
            return new EmailService(
                $this->createMock(MailerInterface::class), 'https://app.zenoo.lt', 'noreply@zenoo.lt', 'ZENOO',
                $this->createMock(TranslatorInterface::class), $klaviyo, $dsn, 'auto',
            );
        };
        $this->assertSame('klaviyo', $make('null://null', 'pk_x')->deliveryFor('en'));
        $this->assertSame('klaviyo', $make('null://null', 'pk_x')->deliveryFor('lt'), 'LT falls back to the main account');
        $this->assertSame('smtp', $make('smtp://u:p@smtp.gmail.com:587', 'pk_x')->deliveryFor('en'));
        $this->assertSame('smtp', $make('null://null', null)->deliveryFor('en'));
    }
}
