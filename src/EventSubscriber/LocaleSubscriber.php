<?php

namespace App\EventSubscriber;

use App\Entity\User;
use App\Locale;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Site language (English, Lithuanian, Ukrainian or Polish, see App\Locale), without language
 * prefixes in the URLs.
 *
 * The language comes from, in order: a "?lang=uk" style link parameter (so a shared link opens in
 * that language; it is then remembered like a flag click), the "zenoo_lang" cookie (set by the flags,
 * LanguageController), then the address and the browser: on a .lt host such as app.zenoo.lt the page
 * is Lithuanian unless the browser prefers Ukrainian or Polish; elsewhere the browser's preferred
 * language, then English.
 * On login: if this browser has a language cookie, that latest choice is saved to the account;
 * otherwise (a new browser) the account's language is used and written to the cookie.
 */
final class LocaleSubscriber implements EventSubscriberInterface
{
    public const COOKIE = 'zenoo_lang';
    public const LOCALES = Locale::ALL;

    private ?string $loginLocale = null;
    private ?string $linkLocale = null;

    public function __construct(private readonly EntityManagerInterface $em, private readonly Security $security)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Before Symfony's own LocaleListener (priority 16).
            KernelEvents::REQUEST => [['onRequest', 20]],
            KernelEvents::RESPONSE => [['onResponse', 0]],
            LoginSuccessEvent::class => [['onLogin', 0]],
        ];
    }

    public static function fromRequest(Request $request): string
    {
        $cookie = $request->cookies->get(self::COOKIE);
        if (\in_array($cookie, self::LOCALES, true)) {
            return $cookie;
        }
        // A Lithuanian address (app.zenoo.lt) opens in Lithuanian, unless the browser asks for
        // Ukrainian or Polish (getPreferredLanguage gives the first of the list when nothing matches).
        if (str_ends_with(strtolower($request->getHost()), '.lt')) {
            return $request->getPreferredLanguage(['lt', 'uk', 'pl']) ?? 'lt';
        }
        return $request->getPreferredLanguage(self::LOCALES) ?? Locale::DEFAULT;
    }

    public static function cookie(string $locale): Cookie
    {
        return Cookie::create(self::COOKIE, $locale, new \DateTimeImmutable('+1 year'), '/', null, null, false, false, Cookie::SAMESITE_LAX);
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $link = $request->query->get('lang');
        if ($event->isMainRequest() && \in_array($link, self::LOCALES, true)) {
            // Remembered from now on, exactly as if the visitor had clicked the flag.
            $this->linkLocale = $link;
            $request->cookies->set(self::COOKIE, $link);
            $request->setLocale($link);
            return;
        }
        $request->setLocale(self::fromRequest($request));
    }

    public function onLogin(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }
        $chosen = $event->getRequest()->cookies->get(self::COOKIE);
        if (\in_array($chosen, self::LOCALES, true)) {
            if ($user->getLocale() !== $chosen) {
                $user->setLocale($chosen);
                $this->em->flush();
            }
            return;
        }
        $this->loginLocale = $user->getLocale();
        $event->getRequest()->setLocale($user->getLocale());
    }

    public function onResponse(ResponseEvent $event): void
    {
        if ($this->linkLocale !== null && $event->isMainRequest()) {
            $event->getResponse()->headers->setCookie(self::cookie($this->linkLocale));
            $user = $this->security->getUser();
            if ($user instanceof User && $user->getLocale() !== $this->linkLocale) {
                $user->setLocale($this->linkLocale);
                $this->em->flush();
            }
            $this->linkLocale = null;
        }
        if ($this->loginLocale !== null && $event->isMainRequest()) {
            $event->getResponse()->headers->setCookie(self::cookie($this->loginLocale));
            $this->loginLocale = null;
        }
    }
}
