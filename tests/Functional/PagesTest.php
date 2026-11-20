<?php

namespace App\Tests\Functional;

use App\Tests\Support\FakeStripe;

class PagesTest extends AppTestCase
{
    /** @dataProvider publicPages */
    public function testPublicPagesLoad(string $path): void
    {
        $this->createProduct();
        $this->client->request('GET', $path);
        $this->assertResponseIsSuccessful();
    }

    public static function publicPages(): iterable
    {
        foreach (['/', '/shop', '/shop/test-product', '/login', '/register', '/forgot-password', '/privacy-policy', '/quiz/', '/health'] as $p) {
            yield $p => [$p];
        }
    }

    public function testQuizPageSendsToKlaviyo(): void
    {
        $crawler = $this->client->request('GET', '/quiz/');
        $script = $crawler->filter('script')->reduce(fn ($s) => str_contains($s->text(), 'ZQ_CONFIG'))->first()->text();
        $this->assertStringContainsString('"sendToKlaviyo":true', $script);
        $this->assertStringContainsString('"klaviyoCompanyId":"SVCcfJ"', $script);
        $this->assertStringContainsString('"klaviyoListId":"Xubzza"', $script);
        $this->assertSelectorExists('script[src="/quiz/app.js"]');
        $this->assertCount(0, FakeStripe::$requests);
    }

    public function testQuizScoringAndStylesMatchTheHandoff(): void
    {
        // styles.css is an untouched copy of the ZENOO handoff; scoring.js was changed on purpose for the
        // 20 Nov 2026 test report (feeding plan follows body shape, puppy growth, score caps: thresholds
        // to be signed off by a vet). The hashes guard against silent edits: update them with any
        // deliberate change. app.js and content.*.js are changed on purpose all the time.
        $expected = [
            'scoring.js' => '2c0cb9d39ae83583fc86a2e91587090cec7086070845eb8d073c259eae2c0e9c',
            'styles.css' => '3341429afe83cb14429dee3f008dc3276a014ecef9e02352b0ec0f62e2d02572',
        ];
        foreach ($expected as $file => $hash) {
            $this->assertSame($hash, hash_file('sha256', dirname(__DIR__, 2) . '/public/quiz/' . $file), $file . ' differs from the handoff copy');
        }
    }

    public function testLoggedInHomeIsTheDashboard(): void
    {
        $this->client->request('GET', '/');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('section.hero');

        $this->client->loginUser($this->createUser());
        $this->client->request('GET', '/');
        $this->assertResponseRedirects('/dashboard');
        $crawler = $this->client->request('GET', '/dashboard');
        $this->assertSame('/dashboard', $crawler->filter('a.brand')->attr('href'));
    }

    public function testQuizUsesTheAppsPrivacyPageAndSkipsTheGateWhenLoggedIn(): void
    {
        $config = function (): array {
            $html = $this->client->request('GET', '/quiz/')->html();
            preg_match('/window\.ZQ_CONFIG = (\{.*?\});/s', $html, $m);
            return json_decode($m[1], true);
        };
        $cfg = $config();
        $this->assertStringEndsWith('/privacy-policy', $cfg['privacyUrl']);
        $this->assertNull($cfg['user']);

        $this->client->loginUser($this->createUser());
        $this->assertSame('maria@example.com', $config()['user']['email']);
        // The email step is skipped for them, and the opt-in is unticked by default.
        $app = (string) file_get_contents(dirname(__DIR__, 2) . '/public/quiz/app.js');
        $this->assertStringContainsString("{ id: 'gate', when: () => !CFG.user", $app);
        $this->assertStringContainsString("data-check=\"marketing\" \${a.marketing ? 'checked' : ''}", $app);
        $this->assertStringContainsString('p.marketing && klaviyo(\'subscriptions\'', $app);
    }

    public function testUnknownPageIs404(): void
    {
        $this->client->request('GET', '/no-such-page');
        $this->assertResponseStatusCodeSame(404);
    }
}
