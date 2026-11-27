<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Klaviyo's server API (private key): profile properties and events.
 *
 * - setProperties(): the quiz's personal "dashboard_link" on the profile, for the quiz flow's email
 *   button ({{ person|lookup:'dashboard_link' }}).
 * - trackEvent(): site emails sent through Klaviyo flows instead of SMTP (see EmailService).
 *
 * Used for the quiz's personal "dashboard_link": the Klaviyo quiz flow's email shows it as a button
 * ({{ person|lookup:'dashboard_link' }}). The link logs its holder into that email's account, so it
 * must only ever reach Klaviyo from here, never from the visitor's browser.
 *
 * - setMarketingConsent(): the app's "tips and offers" choice as Klaviyo email-marketing consent, so
 *   the marketing flow (Plan series) reaches the people who said yes, and stops when they say no.
 *
 * KLAVIYO_PRIVATE_KEY (main account) / KLAVIYO_LT_PRIVATE_KEY (Lithuanian, if it has its own
 * account) need "Profiles: Full access", plus "Events: Full access" for site emails and
 * "Subscriptions" + "Lists: Full access" for the consent. Empty key = nothing is sent to Klaviyo.
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
        #[Autowire('%app.quiz.klaviyo_list_id%')] private readonly ?string $listEn = null,
        #[Autowire('%app.quiz.klaviyo_lt_list_id%')] private readonly ?string $listLt = null,
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
        return $this->post(self::ENDPOINT, $this->key($locale), [
            'type' => 'profile',
            'attributes' => ['email' => $email, 'properties' => $properties],
        ], 'profile update');
    }

    /** Site emails as Klaviyo events: the Lithuanian key if there is one, else the main account's. */
    public function canSendEvents(string $locale): bool
    {
        return $this->eventKey($locale) !== '';
    }

    /**
     * Records an event (e.g. "ZENOO Password Reset") on the profile for $email. A Klaviyo flow
     * triggered by that metric sends the actual email, using these properties ({{ event.reset_link }}).
     *
     * @param array<string, scalar|null> $properties
     * @param array<string, scalar|null> $profile extra profile attributes (first_name, locale)
     */
    public function trackEvent(string $email, string $locale, string $metric, array $properties, array $profile = []): bool
    {
        $key = $this->eventKey($locale);
        if ($key === '') {
            return false;
        }
        return $this->post('https://a.klaviyo.com/api/events/', $key, [
            'type' => 'event',
            'attributes' => [
                'properties' => $properties,
                'metric' => ['data' => ['type' => 'metric', 'attributes' => ['name' => $metric]]],
                'profile' => ['data' => ['type' => 'profile', 'attributes' => ['email' => $email] + array_filter($profile, fn ($v) => $v !== null && $v !== '')]],
                'unique_id' => bin2hex(random_bytes(16)),
            ],
        ], 'event ' . $metric);
    }

    /**
     * Email-marketing consent for $email: subscribed (and added to the quiz's list) or unsubscribed.
     * Only call it on the person's own choice (the quiz tick, the account toggle, the unsubscribe
     * link), never in bulk: subscribing again would undo an unsubscribe made in Klaviyo. Never throws.
     */
    public function setMarketingConsent(string $email, string $locale, bool $subscribed): bool
    {
        $key = $this->eventKey($locale);
        if ($key === '') {
            return false;
        }
        $profile = ['data' => [[
            'type' => 'profile',
            'attributes' => [
                'email' => $email,
                'subscriptions' => ['email' => ['marketing' => ['consent' => $subscribed ? 'SUBSCRIBED' : 'UNSUBSCRIBED']]],
            ],
        ]]];
        if (!$subscribed) {
            // No list: unsubscribed from email marketing in the whole account.
            return $this->post('https://a.klaviyo.com/api/profile-subscription-bulk-delete-jobs/', $key, [
                'type' => 'profile-subscription-bulk-delete-job',
                'attributes' => ['profiles' => $profile],
            ], 'unsubscribe');
        }
        $data = [
            'type' => 'profile-subscription-bulk-create-job',
            'attributes' => ['custom_source' => 'ZENOO app', 'profiles' => $profile],
        ];
        $list = $this->listFor($locale);
        if ($list !== '') {
            $data['relationships'] = ['list' => ['data' => ['type' => 'list', 'id' => $list]]];
        }
        return $this->post('https://a.klaviyo.com/api/profile-subscription-bulk-create-jobs/', $key, $data, 'subscribe');
    }

    /** The list in the account whose key is used: the Lithuanian one only when Lithuanian has its own key. */
    private function listFor(string $locale): string
    {
        if ($locale === 'lt' && trim((string) $this->keyLt) !== '') {
            return trim((string) $this->listLt);
        }
        return trim((string) $this->listEn);
    }

    private function key(string $locale): string
    {
        return trim((string) ($locale === 'lt' ? $this->keyLt : $this->keyEn));
    }

    private function eventKey(string $locale): string
    {
        $key = $this->key($locale);
        return $key !== '' ? $key : trim((string) $this->keyEn);
    }

    private function post(string $url, string $key, array $data, string $what): bool
    {
        try {
            $response = $this->http->request('POST', $url, [
                'timeout' => 6,
                'headers' => [
                    'Authorization' => 'Klaviyo-API-Key ' . $key,
                    'revision' => self::REVISION,
                    'Content-Type' => 'application/vnd.api+json',
                    'Accept' => 'application/vnd.api+json',
                ],
                'body' => json_encode(['data' => $data], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
            $status = $response->getStatusCode();
            if ($status >= 200 && $status < 300) {
                return true;
            }
            $this->logger->error('Klaviyo {what} failed: HTTP {status} {body}', [
                'what' => $what,
                'status' => $status,
                'body' => mb_substr($response->getContent(false), 0, 500),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Klaviyo {what} failed: {message}', ['what' => $what, 'message' => $e->getMessage()]);
        }
        return false;
    }
}
