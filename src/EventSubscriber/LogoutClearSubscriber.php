<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Signing out leaves nothing of the member's in the browser's storage (F7): the quiz keeps its
 * answers in localStorage, and the next person on a shared device mustn't see them. Browsers that
 * ignore Clear-Site-Data are covered by the quiz itself, which drops a member's saved quiz when
 * nobody (or someone else) is signed in.
 */
final class LogoutClearSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [LogoutEvent::class => ['onLogout', -10]];
    }

    public function onLogout(LogoutEvent $event): void
    {
        $event->getResponse()?->headers->set('Clear-Site-Data', '"storage"');
    }
}
