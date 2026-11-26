<?php

namespace App\EventSubscriber;

use App\Entity\User;
use App\Payment\Subscriptions;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The paywall: signed-in members without a trial or a paid plan are sent to /trial from the parts of
 * the app the trial opens (Today, the dog's page and plan, the coach, the lessons). The quiz, the
 * score, the account and logging out stay open. Off while Subscriptions::isEnabled() is false.
 */
final class PaywallSubscriber implements EventSubscriberInterface
{
    /** Paths the trial opens. */
    private const LOCKED = ['/dashboard', '/chat', '/courses', '/photo-handoff'];

    public function __construct(
        private readonly Subscriptions $subscriptions,
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // After the firewall (priority 8) has signed the user in.
        return [KernelEvents::REQUEST => [['onRequest', 0]]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $path = $event->getRequest()->getPathInfo();
        $locked = false;
        foreach (self::LOCKED as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                $locked = true;
                break;
            }
        }
        if (!$locked) {
            return;
        }
        $user = $this->security->getUser();
        if (!$user instanceof User || $this->subscriptions->hasAccess($user, $this->security->isGranted('ROLE_ADMIN'))) {
            return; // visitors get the usual preview or login; members with a trial or plan go on
        }
        $request = $event->getRequest();
        $event->setResponse(
            $request->isXmlHttpRequest() || $request->getMethod() !== 'GET' || str_contains((string) $request->headers->get('Accept'), 'json')
                ? new JsonResponse(['error' => 'trial', 'url' => $this->urls->generate('app_trial')], 402)
                : new RedirectResponse($this->urls->generate('app_trial'))
        );
    }
}
