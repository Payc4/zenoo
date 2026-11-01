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
        $script = $crawler->filter('script')->first()->text();
        $this->assertStringContainsString('"sendToKlaviyo":true', $script);
        $this->assertStringContainsString('"klaviyoCompanyId":"SVCcfJ"', $script);
        $this->assertStringContainsString('"klaviyoListId":"Xubzza"', $script);
        $this->assertSelectorExists('script[src="/quiz/app.js"]');
        $this->assertCount(0, FakeStripe::$requests);
    }

    public function testQuizFilesMatchTheHandoff(): void
    {
        // public/quiz/*.js and styles.css are copies of the ZENOO handoff; the hashes guard against silent edits.
        $expected = [
            'app.js' => '213c2dc66a275a58303e391efb33e05a5bcf5944f457cb70c8c1c0f2483ddb92',
            'scoring.js' => 'f288dafd778bb1dae7c79a19e121b2dbca1f4b1e468dad739e8fa2857cdbb1e7',
            'content.en.js' => '7a0967762bf5b66249ecfa27aa6e307abc68298277fcfede7de8735e02b1f611',
            'styles.css' => '3341429afe83cb14429dee3f008dc3276a014ecef9e02352b0ec0f62e2d02572',
        ];
        foreach ($expected as $file => $hash) {
            $this->assertSame($hash, hash_file('sha256', dirname(__DIR__, 2) . '/public/quiz/' . $file), $file . ' differs from the handoff copy');
        }
    }

    public function testUnknownPageIs404(): void
    {
        $this->client->request('GET', '/no-such-page');
        $this->assertResponseStatusCodeSame(404);
    }
}
