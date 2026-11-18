<?php

namespace App\Tracking;

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Where a visitor came from: campaign tags (utm_*), ad click IDs (gclid, fbclid, ttclid, msclkid),
 * a partner code (?ref=) and the referring site. Kept in the session (first touch and last touch).
 * With analytics consent it's also kept for 90 days in the zn_src cookie, so a visitor who comes
 * back later still counts for the campaign that brought them. Saved on the lead and the account.
 */
final class Attribution
{
    public const COOKIE = 'zn_src';
    private const PARAMS = ['utm_source' => 'source', 'utm_medium' => 'medium', 'utm_campaign' => 'campaign', 'utm_content' => 'content', 'utm_term' => 'term',
        'gclid' => 'gclid', 'fbclid' => 'fbclid', 'ttclid' => 'ttclid', 'msclkid' => 'msclkid', 'ref' => 'ref'];

    /** Reads this request's campaign parameters into the session. Call on page views (GET, HTML). */
    public function capture(Request $request): void
    {
        if (!$request->hasSession()) {
            return;
        }
        $session = $request->getSession();
        $data = $session->get('attribution');
        if ($data === null && ($cookie = $request->cookies->get(self::COOKIE))) {
            $data = json_decode((string) $cookie, true) ?: null;
        }
        $touch = [];
        foreach (self::PARAMS as $param => $key) {
            $v = $request->query->get($param);
            if (\is_string($v) && $v !== '') {
                $touch[$key] = mb_substr(trim($v), 0, 120);
            }
        }
        $referrer = (string) $request->headers->get('referer', '');
        $refHost = $referrer !== '' ? (string) parse_url($referrer, PHP_URL_HOST) : '';
        $external = $refHost !== '' && $refHost !== $request->getHost();
        if (!$touch && $data !== null && !$external) {
            return; // an ordinary page view inside the site
        }
        if (!$touch) {
            $touch = $external ? ['source' => $refHost, 'medium' => 'referral'] : ['source' => '(direct)', 'medium' => '(none)'];
        } elseif (!isset($touch['source'])) {
            $touch['source'] = match (true) {
                isset($touch['gclid']) => 'google',
                isset($touch['fbclid']) => 'facebook',
                isset($touch['ttclid']) => 'tiktok',
                isset($touch['msclkid']) => 'bing',
                isset($touch['ref']) => 'partner',
                default => $external ? $refHost : '(direct)',
            };
            $touch['medium'] ??= isset($touch['ref']) ? 'partner' : (isset($touch['gclid']) || isset($touch['fbclid']) || isset($touch['ttclid']) || isset($touch['msclkid']) ? 'cpc' : 'referral');
        }
        $touch += ['referrer' => $external ? mb_substr($referrer, 0, 300) : null, 'landing' => mb_substr($request->getPathInfo(), 0, 200), 'at' => (new \DateTimeImmutable())->format(\DATE_ATOM)];
        $data = ['first' => $data['first'] ?? $touch, 'last' => $touch];
        $session->set('attribution', $data);
    }

    public function get(Request $request): ?array
    {
        return $request->hasSession() ? $request->getSession()->get('attribution') : null;
    }

    /** The label funnel counts use: "facebook / cpc", "(direct)", "partner: vetklinika". */
    public static function label(?array $data): string
    {
        $t = $data['first'] ?? null;
        if (!$t) {
            return '(unknown)';
        }
        if (isset($t['ref'])) {
            return mb_substr('partner: ' . $t['ref'], 0, 80);
        }
        return mb_substr($t['source'] . (isset($t['medium']) && $t['medium'] !== '(none)' ? ' / ' . $t['medium'] : ''), 0, 80);
    }

    /** With analytics consent, keeps the attribution for 90 days. */
    public function persist(Request $request, Response $response): void
    {
        $data = $this->get($request);
        if ($data !== null && Consent::allows($request, 'analytics')) {
            $response->headers->setCookie(Cookie::create(self::COOKIE, json_encode($data), new \DateTimeImmutable('+90 days'), '/', null, $request->isSecure(), true, false, Cookie::SAMESITE_LAX));
        }
    }
}
