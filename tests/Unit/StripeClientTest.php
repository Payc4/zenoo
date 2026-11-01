<?php

namespace App\Tests\Unit;

use App\Payment\StripeClient;
use App\Payment\StripeException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

class StripeClientTest extends TestCase
{
    private function client(string $key = 'sk_test_x', string $secret = 'whsec_x'): StripeClient
    {
        return new StripeClient(new MockHttpClient(), $key, $secret);
    }

    public function testEncodeNestedParams(): void
    {
        $this->assertSame(
            'line_items%5B0%5D%5Bquantity%5D=1&allow_promotion_codes=true&skip=false',
            StripeClient::encode(['line_items' => [['quantity' => 1]], 'allow_promotion_codes' => true, 'skip' => false, 'none' => null]),
        );
    }

    public function testReadinessAndTestMode(): void
    {
        $this->assertTrue($this->client()->isReady());
        $this->assertTrue($this->client()->isTestMode());
        $this->assertFalse($this->client('sk_live_x')->isTestMode());
        $this->assertFalse($this->client('sk_test_x', '')->isReady(), 'no webhook secret, no checkout');
    }

    public function testWebhookSignature(): void
    {
        $c = $this->client();
        $payload = '{"type":"x","data":{"object":{}}}';
        $this->assertSame('x', $c->verifyWebhook($payload, $c->signPayload($payload, 1000), 1000)['type']);

        foreach ([null, 't=1000', 't=1000,v1=abc', $c->signPayload($payload . ' ', 1000)] as $bad) {
            try {
                $c->verifyWebhook($payload, $bad, 1000);
                $this->fail('accepted a bad signature: ' . var_export($bad, true));
            } catch (StripeException $e) {
                $this->assertSame(400, $e->getCode());
            }
        }

        $this->expectException(StripeException::class);
        $c->verifyWebhook($payload, $c->signPayload($payload, 1000), 1000 + StripeClient::WEBHOOK_TOLERANCE + 1);
    }

    public function testRejectsBadSessionIds(): void
    {
        $this->expectException(StripeException::class);
        $this->client()->retrieveCheckoutSession('../../v1/charges');
    }
}
