<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** robots.txt and sitemap.xml, listing public pages only (test report M7). */
final class SeoController extends AbstractController
{
    /** Public pages worth finding in search. Member pages, shared results and the admin are left out. */
    private const PAGES = ['app_homepage', 'app_quiz', 'app_method', 'app_courses', 'app_chat', 'app_privacy_policy'];

    #[Route('/robots.txt', name: 'app_robots', methods: ['GET'])]
    public function robots(): Response
    {
        $body = "User-agent: *\n"
            . "Disallow: /admin\nDisallow: /account\nDisallow: /dashboard\nDisallow: /chat/\nDisallow: /courses/\nDisallow: /s/\nDisallow: /p/\nDisallow: /photo-handoff\nDisallow: /quiz/confirm\nDisallow: /login\nDisallow: /register\nDisallow: /reset-password\n\n"
            . 'Sitemap: ' . $this->generateUrl('app_sitemap', [], UrlGeneratorInterface::ABSOLUTE_URL) . "\n";
        return new Response($body, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    #[Route('/sitemap.xml', name: 'app_sitemap', methods: ['GET'])]
    public function sitemap(): Response
    {
        $urls = '';
        foreach (self::PAGES as $route) {
            $urls .= '  <url><loc>' . htmlspecialchars($this->generateUrl($route, [], UrlGeneratorInterface::ABSOLUTE_URL), ENT_XML1) . "</loc></url>\n";
        }
        return new Response("<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n$urls</urlset>\n", 200, ['Content-Type' => 'application/xml; charset=utf-8']);
    }
}
