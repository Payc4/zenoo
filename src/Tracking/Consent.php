<?php

namespace App\Tracking;

use Symfony\Component\HttpFoundation\Request;

/** The cookie banner's choice (public/consent.js): zn_consent = "necessary", "necessary,analytics" or "necessary,analytics,marketing". */
final class Consent
{
    public const COOKIE = 'zn_consent';

    public static function allows(Request $request, string $category): bool
    {
        return \in_array($category, explode(',', (string) $request->cookies->get(self::COOKIE, '')), true);
    }
}
