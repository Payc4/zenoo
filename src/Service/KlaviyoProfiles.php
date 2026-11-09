<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Writes properties onto a Klaviyo profile from the server (Klaviyo's server API, private key).
 *
 * Used for the quiz's personal "dashboard_link": the Klaviyo quiz flow's email shows it as a button
 * ({{ person|lookup:'dashboard_link' }}). The link logs its holder into that email's account, so it
 * must only ever reach Klaviyo from here, never from the visitor's browser.
 *
 * KLAVIYO_PRIVATE_KEY (English quiz account) / KLAVIYO_LT_PRIVATE_KEY (Lithuanian, if it has its own
 * account) need only "Profiles: Full access". Empty key = nothing is written, the site's own
 * confirmation email carries the link instead.
 */
final class KlaviyoProfiles
{
    private const ENDPOINT = 'https://a.klaviyo.com/api/profile-import/';
    private const REVISION = '2025-01-15';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(default::KLAVIYO_PRIVATE_KEY)%')] private readonly ?string $keyEn,
        #[Autowire('%env(default::KLAVIYO_LT_PRIVATE_KEY)%')] private readonly ?string $keyLt,
        #[Autowire('%app.quiz.send_to_klaviyo%')] private readonly bool $enabled,
    ) {
    }

    public function isConfigured(string $locale): bool
    {
        return $this->enabled && $this->key($locale) !== '';
    }

    /**
     * Creates or updates the profile for $email with these properties. Never throws.
     *
     * @param array<string, scalar> $properties
     * @return bool whether Klaviyo accepted it
     */
    public function setProperties(string $email, string $locale, array $properties): bool
    {
        if (!$this->isConfigured($locale)) {
            return false;
        }
        try {
            $response = $this->http->request('POST', self::ENDPOINT, [
                'timeout' => 6,
                'headers' => [
                    'Authorization' => 'Klaviyo-API-Key ' . $this->key($locale),
                    'revision' => self::REVISION,
                    'Content-Type' => 'application/vnd.api+json',
                    'Accept' => 'application/vnd.api+json',
                ],
                'body' => json_encode(['data' => [
                    'type' => 'profile',
                    'attributes' => ['email' => $email, 'properties' => $properties],
                ]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ]);
            $status = $response->getStatusCode();
            if ($status >= 200 && $status < 300) {
                return true;
            }
            $this->logger->error('Klaviyo profile update failed: HTTP {status} {body}', [
                'status' => $status,
                'body' => mb_substr($response->getContent(false), 0, 500),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Klaviyo profile update failed: {message}', ['message' => $e->getMessage()]);
        }
        return false;
    }

    private function key(string $locale): string
    {
        return trim((string) ($locale === 'lt' ? $this->keyLt : $this->keyEn));
    }
}
