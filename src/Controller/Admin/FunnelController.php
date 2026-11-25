<?php

namespace App\Controller\Admin;

use App\Tracking\Funnel;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Roadmap 03: the funnel step by step, and by traffic source (first touch), for the last 7, 30 or 90 days. */
#[IsGranted('ROLE_ADMIN')]
final class FunnelController extends AbstractController
{
    #[Route('/admin/funnel', name: 'admin_funnel', methods: ['GET'])]
    public function index(
        Request $request,
        Funnel $funnel,
        \App\Repository\CatalogueProductRepository $catalogue,
        \App\Catalogue\PlanBuilder $plans,
        \App\Repository\ProductRepository $products,
    ): Response {
        // Is measurement live? (test report N8): what's set and what's missing, before any paid traffic.
        $env = static fn (string $k) => trim((string) ($_SERVER[$k] ?? $_ENV[$k] ?? getenv($k) ?: '')) !== '';
        $setup = [
            ['Email sending (MAILER_DSN or KLAVIYO_PRIVATE_KEY)', \App\Service\EmailService::deliveryConfigured(), 'No email leaves the site: set MAILER_DSN (SMTP) or KLAVIYO_PRIVATE_KEY in Coolify, then redeploy.'],
            ['Google Analytics 4 (TRACKING_GA4_ID)', $env('TRACKING_GA4_ID'), 'Set the GA4 measurement ID (G-…) in Coolify.'],
            ['Meta pixel (TRACKING_META_PIXEL_ID)', $env('TRACKING_META_PIXEL_ID'), 'Set the pixel ID in Coolify.'],
            ['Klaviyo, English quiz', $env('KLAVIYO_COMPANY_ID') && $env('KLAVIYO_LIST_ID'), 'KLAVIYO_COMPANY_ID and KLAVIYO_LIST_ID.'],
            ['Klaviyo, Lithuanian quiz', $env('KLAVIYO_LT_COMPANY_ID') && $env('KLAVIYO_LT_LIST_ID'), 'KLAVIYO_LT_COMPANY_ID and KLAVIYO_LT_LIST_ID.'],
            ['Klaviyo, Ukrainian quiz', $env('KLAVIYO_UK_COMPANY_ID') && $env('KLAVIYO_UK_LIST_ID'), 'KLAVIYO_UK_COMPANY_ID and KLAVIYO_UK_LIST_ID (optional: the site sends its own Ukrainian emails).'],
            ['Klaviyo, Polish quiz', $env('KLAVIYO_PL_COMPANY_ID') && $env('KLAVIYO_PL_LIST_ID'), 'KLAVIYO_PL_COMPANY_ID and KLAVIYO_PL_LIST_ID (optional: the site sends its own Polish emails).'],
            ['Shop products for the ZENOO plan', $plans->mainFood() !== null, \count($catalogue->findAll()) . ' products synced, none usable as a main food. Run app:catalogue:sync (it also runs on every deploy).'],
            ['Stripe payments (STRIPE_SECRET_KEY)', $env('STRIPE_SECRET_KEY'), 'Set the Stripe keys and webhook secret.'],
            ['Shop has real products', \count(array_filter($products->findActive(), fn ($p) => (float) $p->getPrice() > 1)) > 0, 'Only test products are on sale.'],
        ];
        $days = \in_array($request->query->getInt('days', 30), [7, 30, 90], true) ? $request->query->getInt('days', 30) : 30;
        $report = $funnel->report(new \DateTimeImmutable("-$days days"));
        return $this->render('admin/funnel/index.html.twig', [
            'days' => $days,
            'labels' => Funnel::STEPS,
            'short' => Funnel::SHORT,
            'steps' => $report['steps'],
            'sources' => $report['sources'],
            'setup' => $setup,
        ]);
    }
}
