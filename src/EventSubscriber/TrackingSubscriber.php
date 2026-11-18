<?php

namespace App\EventSubscriber;

use App\Tracking\Attribution;
use App\Tracking\Funnel;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/** Captures where visitors come from on page views, and counts one "landing" per visit. */
final class TrackingSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly Attribution $attribution, private readonly Funnel $funnel)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', -10], KernelEvents::RESPONSE => 'onResponse'];
    }

    private static function isPageView(\Symfony\Component\HttpFoundation\Request $r): bool
    {
        return $r->isMethod('GET') && !$r->isXmlHttpRequest()
            && !preg_match('~^/(admin|_|t$|uploads/|assets/|quiz/assets/|chat/file|coach-emails|language/)~', $r->getPathInfo())
            && !str_contains((string) $r->headers->get('accept', ''), 'text/event-stream');
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !self::isPageView($request) || !$request->hasSession()) {
            return;
        }
        $this->attribution->capture($request);
        $session = $request->getSession();
        if (!$session->get('funnel_landed')) {
            $session->set('funnel_landed', true);
            $this->funnel->record('landing');
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        if ($event->isMainRequest() && self::isPageView($event->getRequest())) {
            $this->attribution->persist($event->getRequest(), $event->getResponse());
        }
    }
}
