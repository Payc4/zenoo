<?php

namespace App\Tests\Functional;

use App\Entity\QuizSubmission;
use App\Entity\User;
use App\Tracking\Funnel;

/** Roadmap 03: campaign source on leads and accounts, funnel counts, consent before GA4/Meta. */
final class MeasurementTest extends AppTestCase
{
    private function report(): array
    {
        return static::getContainer()->get(Funnel::class)->report(new \DateTimeImmutable('-1 day'));
    }

    public function testACampaignVisitBecomesALeadAndAnAccountWithItsSource(): void
    {
        $this->client->request('GET', '/quiz/?utm_source=facebook&utm_medium=cpc&utm_campaign=bowl-nov&fbclid=abc123');
        $html = $this->client->getResponse()->getContent();
        preg_match('/window\.ZQ_CONFIG = (\{.*?\});/s', $html, $m);
        $token = json_decode($m[1], true)['accountToken'];

        // Inside the visit, more pages don't change the source or count another landing.
        $this->client->request('GET', '/privacy-policy');
        $this->client->request('POST', '/t', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['e' => 'quiz_start']));
        $this->client->request('POST', '/t', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['e' => 'quiz_start']));
        $this->client->request('POST', '/t', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['e' => 'evil']));
        $this->assertResponseStatusCodeSame(204);

        $this->client->request('POST', '/quiz/submit', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], json_encode([
            'email' => 'new@example.com', 'first_name' => 'Ona', 'locale' => 'en', 'properties' => QuizAccountTest::result(),
        ]));
        $this->assertResponseIsSuccessful();
        $sub = $this->em->getRepository(QuizSubmission::class)->findOneBy(['email' => 'new@example.com']);
        $this->assertSame('bowl-nov', $sub->getData()['_attribution']['first']['campaign']);

        $report = $this->report();
        $this->assertSame(1, $report['steps']['landing']);
        $this->assertSame(1, $report['steps']['quiz_start']);
        $this->assertSame(1, $report['steps']['quiz_submit']);
        $this->assertSame(1, $report['sources']['facebook / cpc']['quiz_submit']);

        // Confirming makes the account, which keeps the source; the dog profile doesn't get it.
        $token = (new \ReflectionProperty(QuizSubmission::class, 'tokenHash'));
        $raw = bin2hex(random_bytes(32));
        $token->setValue($sub, hash('sha256', $raw));
        $this->em->flush();
        $this->client->request('GET', '/quiz/confirm/' . $raw);
        $this->em->clear();
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'new@example.com']);
        $this->assertNotNull($user, 'account created');
        $this->assertSame('abc123', $user->getAttribution()['first']['fbclid']);
        $this->assertSame(1, $this->report()['steps']['account_created']);
    }

    public function testAdBlocksAndBannerOnlyWithConsentAndIds(): void
    {
        // No GA4/Meta IDs: no banner config with IDs, nothing loads.
        $html = $this->client->request('GET', '/quiz/')->html();
        $this->assertStringContainsString('"ga4":null', $html);
        $this->assertStringContainsString('/consent.js', $html);
        $this->assertStringNotContainsString('googletagmanager', $html);
        $this->assertStringNotContainsString('zn_src', implode(';', array_map(fn ($c) => $c->getName(), $this->client->getResponse()->headers->getCookies())));

        // With analytics consent, the source is kept in a 90-day cookie.
        $this->client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie('zn_consent', 'necessary,analytics'));
        $this->client->request('GET', '/?utm_source=newsletter');
        $names = array_map(fn ($c) => $c->getName(), $this->client->getResponse()->headers->getCookies());
        $this->assertContains('zn_src', $names);
    }

    public function testAdminFunnelPage(): void
    {
        $this->client->request('GET', '/?ref=vetklinika');
        $this->client->loginUser($this->createUser('admin@example.com', 'Password123', ['ROLE_ADMIN']));
        $this->client->request('GET', '/admin/funnel?days=7');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('main', 'partner: vetklinika');
    }
}
