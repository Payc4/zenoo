<?php

namespace App\Twig;

use App\Tracking\Funnel;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** tracking_config() for public/consent.js; tracking_events() hands over server-side steps once. */
final class TrackingExtension extends AbstractExtension
{
    public function __construct(
        private readonly RequestStack $requests,
        #[Autowire('%env(default::TRACKING_GA4_ID)%')] private readonly ?string $ga4,
        #[Autowire('%env(default::TRACKING_META_PIXEL_ID)%')] private readonly ?string $pixel,
        #[Autowire('%env(default::TRACKING_HOTJAR_ID)%')] private readonly ?string $hotjar = null,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('tracking_config', [$this, 'config']), new TwigFunction('tracking_events', [$this, 'events'])];
    }

    public function config(): array
    {
        // Hotjar (heatmaps, recordings) counts as analytics: it loads only once that is allowed.
        $hotjar = preg_match('/^\d{4,12}$/', (string) $this->hotjar) ? (int) $this->hotjar : null;
        return ['ga4' => $this->ga4 ?: null, 'pixel' => $this->pixel ?: null, 'hotjar' => $hotjar, 'pixelEvents' => Funnel::PIXEL_EVENTS];
    }

    /** @return list<string> */
    public function events(): array
    {
        $session = $this->requests->getMainRequest()?->hasSession() ? $this->requests->getMainRequest()->getSession() : null;
        if (!$session || !$session->has('zn_pending_events')) {
            return [];
        }
        $events = $session->get('zn_pending_events', []);
        $session->remove('zn_pending_events');
        return $events;
    }
}
