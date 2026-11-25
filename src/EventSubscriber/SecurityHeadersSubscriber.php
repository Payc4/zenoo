<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * M9: HSTS on HTTPS responses, plus a Content-Security-Policy in report-only mode.
 *
 * Report-only first: browsers log (and POST to /csp-report) anything the policy would block,
 * without blocking it. Once the reports stay quiet for a while, the same policy can be sent as
 * Content-Security-Policy. nginx sets nosniff, X-Frame-Options and Referrer-Policy.
 */
final class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    public const CSP = "default-src 'self'; "
        ."script-src 'self' 'unsafe-inline' https://www.googletagmanager.com https://connect.facebook.net https://challenges.cloudflare.com https://static.hotjar.com https://script.hotjar.com; "
        ."style-src 'self' 'unsafe-inline'; "
        ."font-src 'self' https://script.hotjar.com; "
        ."img-src 'self' data: blob: https:; "
        ."media-src 'self' blob:; "
        ."connect-src 'self' https://www.google-analytics.com https://*.google-analytics.com https://*.analytics.google.com https://www.googletagmanager.com https://www.facebook.com https://a.klaviyo.com https://*.hotjar.com https://*.hotjar.io wss://*.hotjar.com; "
        ."frame-src 'self' https://challenges.cloudflare.com https://www.facebook.com https://vars.hotjar.com; "
        ."frame-ancestors 'self'; base-uri 'self'; form-action 'self' https://checkout.stripe.com; object-src 'none'; "
        ."report-uri /csp-report";

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onResponse', -10]];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $headers = $event->getResponse()->headers;
        if ($event->getRequest()->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        if (!$headers->has('Content-Security-Policy') && !$headers->has('Content-Security-Policy-Report-Only')) {
            $headers->set('Content-Security-Policy-Report-Only', self::CSP);
        }
    }
}
