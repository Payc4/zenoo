<?php

namespace App\Controller;

use App\Entity\User;
use App\EventSubscriber\LocaleSubscriber;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The EN / LT selector in the header. Remembers the choice in a cookie (and on the account,
 * so emails are written in it too) and goes back to the page the visitor was on.
 */
final class LanguageController extends AbstractController
{
    #[Route('/language/{locale}', name: 'app_language', requirements: ['locale' => 'en|lt'], methods: ['GET'])]
    public function switch(string $locale, Request $request, EntityManagerInterface $em): RedirectResponse
    {
        $user = $this->getUser();
        if ($user instanceof User && $user->getLocale() !== $locale) {
            $user->setLocale($locale);
            $em->flush();
        }

        // Only go back to a page on this site.
        $back = (string) $request->headers->get('referer', '');
        $target = $this->generateUrl('app_homepage');
        if ($back !== '' && parse_url($back, PHP_URL_HOST) === $request->getHost()) {
            $path = (string) parse_url($back, PHP_URL_PATH);
            $query = parse_url($back, PHP_URL_QUERY);
            if (str_starts_with($path, '/') && !str_starts_with($path, '//') && !str_starts_with($path, '/language/')) {
                $target = $path . ($query ? '?' . $query : '');
            }
        }

        $response = $this->redirect($target);
        $response->headers->setCookie(LocaleSubscriber::cookie($locale));
        return $response;
    }
}
