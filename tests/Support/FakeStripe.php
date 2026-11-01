<?php

namespace App\Tests\Support;

use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Stands in for api.stripe.com in the test environment (wired as the HTTP client's
 * mock_response_factory). Records every request so tests can check what was sent.
 */
final class FakeStripe
{
    /** @var list<array{method: string, url: string, body: string, idempotencyKey: ?string}> */
    public static array $requests = [];
    /** @var array<string, array> Checkout Sessions returned by GET checkout/sessions/{id} */
    public static array $sessions = [];
    public static bool $down = false;

    public static function reset(): void
    {
        self::$requests = [];
        self::$sessions = [];
        self::$down = false;
    }

    public function __invoke(string $method, string $url, array $options = []): MockResponse
    {
        $body = (string) ($options['body'] ?? '');
        $key = null;
        foreach ($options['headers'] ?? [] as $h) {
            if (is_string($h) && stripos($h, 'Idempotency-Key:') === 0) {
                $key = trim(substr($h, 16));
            }
        }
        self::$requests[] = ['method' => $method, 'url' => $url, 'body' => $body, 'idempotencyKey' => $key];

        if (self::$down) {
            return new MockResponse('', ['error' => 'Could not resolve host: api.stripe.com']);
        }
        if ($method === 'POST' && str_ends_with($url, '/v1/checkout/sessions')) {
            $id = 'cs_test_' . substr(sha1($body), 0, 16);

            return new MockResponse(json_encode(['id' => $id, 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/' . $id]));
        }
        if ($method === 'GET' && preg_match('#/v1/checkout/sessions/(cs_[A-Za-z0-9_]+)$#', $url, $m)) {
            $session = self::$sessions[$m[1]] ?? null;

            return $session
                ? new MockResponse(json_encode($session))
                : new MockResponse(json_encode(['error' => ['message' => 'No such checkout.session']]), ['http_code' => 404]);
        }

        return new MockResponse(json_encode(['error' => ['message' => 'Unexpected request in test: ' . $method . ' ' . $url]]), ['http_code' => 500]);
    }

    /** Decodes the form body of the last Checkout Session request. */
    public static function lastCheckoutParams(): array
    {
        foreach (array_reverse(self::$requests) as $r) {
            if ($r['method'] === 'POST' && str_ends_with($r['url'], '/v1/checkout/sessions')) {
                parse_str($r['body'], $params);

                return $params;
            }
        }

        return [];
    }
}
