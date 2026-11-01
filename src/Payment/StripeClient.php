<?php

namespace App\Payment;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Minimal Stripe API client: Checkout Sessions and webhook signature checks.
 * No Stripe SDK needed. Configure with STRIPE_SECRET_KEY and STRIPE_WEBHOOK_SECRET.
 */
class StripeClient
{
    private const API = 'https://api.stripe.com/v1/';
    private const API_VERSION = '2024-06-20';
    /** Webhooks older than this (seconds) are rejected, to stop replays. */
    public const WEBHOOK_TOLERANCE = 300;

    public function __construct(
        private readonly HttpClientInterface $http,
        #[Autowire('%env(default::STRIPE_SECRET_KEY)%')] private readonly ?string $secretKey,
        #[Autowire('%env(default::STRIPE_WEBHOOK_SECRET)%')] private readonly ?string $webhookSecret,
    ) {
    }

    /** Checkout is only offered when both the API key and the webhook secret are set. */
    public function isReady(): bool
    {
        return (string) $this->secretKey !== '' && (string) $this->webhookSecret !== '';
    }

    public function isTestMode(): bool
    {
        return str_starts_with((string) $this->secretKey, 'sk_test_') || str_starts_with((string) $this->secretKey, 'rk_test_');
    }

    /** @return array<string, mixed> the created Checkout Session */
    public function createCheckoutSession(array $params, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', 'checkout/sessions', $params, $idempotencyKey);
    }

    /** @return array<string, mixed> */
    public function retrieveCheckoutSession(string $id): array
    {
        if (!preg_match('/^cs_[A-Za-z0-9_]+$/', $id)) {
            throw new StripeException('Invalid checkout session id.', 400);
        }

        return $this->request('GET', 'checkout/sessions/' . $id);
    }

    /**
     * Checks the Stripe-Signature header and returns the decoded event.
     *
     * @return array<string, mixed>
     * @throws StripeException when the signature is missing, wrong or too old
     */
    public function verifyWebhook(string $payload, ?string $signatureHeader, ?int $now = null): array
    {
        $secret = (string) $this->webhookSecret;
        if ($secret === '') {
            throw new StripeException('STRIPE_WEBHOOK_SECRET is not set.', 500);
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', (string) $signatureHeader) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($k === 't' && ctype_digit($v)) {
                $timestamp = (int) $v;
            } elseif ($k === 'v1' && $v !== '') {
                $signatures[] = $v;
            }
        }
        if ($timestamp === null || !$signatures) {
            throw new StripeException('Missing or malformed Stripe-Signature header.', 400);
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        $valid = false;
        foreach ($signatures as $sig) {
            if (hash_equals($expected, $sig)) {
                $valid = true;
                break;
            }
        }
        if (!$valid) {
            throw new StripeException('Webhook signature does not match.', 400);
        }
        if (abs(($now ?? time()) - $timestamp) > self::WEBHOOK_TOLERANCE) {
            throw new StripeException('Webhook timestamp is too old.', 400);
        }

        $event = json_decode($payload, true);
        if (!is_array($event) || !isset($event['type'], $event['data']['object'])) {
            throw new StripeException('Webhook body is not a Stripe event.', 400);
        }

        return $event;
    }

    /** Builds a valid Stripe-Signature header. Used by tests and the local webhook simulator. */
    public function signPayload(string $payload, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $payload, (string) $this->webhookSecret);
    }

    /** @return array<string, mixed> */
    private function request(string $method, string $path, array $params = [], ?string $idempotencyKey = null): array
    {
        if ((string) $this->secretKey === '') {
            throw new StripeException('STRIPE_SECRET_KEY is not set.', 500);
        }

        $options = [
            'auth_bearer' => $this->secretKey,
            'headers' => ['Stripe-Version' => self::API_VERSION],
            'timeout' => 20,
        ];
        if ($idempotencyKey !== null) {
            // A repeated click or retry with the same key never creates a second session.
            $options['headers']['Idempotency-Key'] = $idempotencyKey;
        }
        if ($params) {
            if ($method === 'GET') {
                $path .= (str_contains($path, '?') ? '&' : '?') . self::encode($params);
            } else {
                $options['body'] = self::encode($params);
                $options['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
            }
        }

        try {
            $response = $this->http->request($method, self::API . $path, $options);
            $status = $response->getStatusCode();
            $data = json_decode($response->getContent(false), true);
        } catch (TransportExceptionInterface $e) {
            // Network trouble (DNS, timeout, firewall): treat it like any other Stripe failure.
            throw new StripeException('Could not reach Stripe: ' . $e->getMessage(), 503, $e);
        }
        if ($status >= 400 || !is_array($data)) {
            $message = is_array($data) ? ($data['error']['message'] ?? 'HTTP ' . $status) : 'HTTP ' . $status;
            throw new StripeException($message, $status, null, is_array($data) ? ($data['error'] ?? []) : []);
        }

        return $data;
    }

    /**
     * Stripe takes nested form fields: line_items[0][price_data][currency]=eur.
     * Booleans must be sent as "true"/"false", not 1/0.
     */
    public static function encode(array $params): string
    {
        $flat = [];
        $walk = function (array $value, string $prefix) use (&$walk, &$flat): void {
            foreach ($value as $k => $v) {
                $key = $prefix === '' ? (string) $k : $prefix . '[' . $k . ']';
                if (is_array($v)) {
                    $walk($v, $key);
                } elseif ($v !== null) {
                    $flat[] = rawurlencode($key) . '=' . rawurlencode(is_bool($v) ? ($v ? 'true' : 'false') : (string) $v);
                }
            }
        };
        $walk($params, '');

        return implode('&', $flat);
    }
}
