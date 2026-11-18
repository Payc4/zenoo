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
    public function index(Request $request, Funnel $funnel): Response
    {
        $days = \in_array($request->query->getInt('days', 30), [7, 30, 90], true) ? $request->query->getInt('days', 30) : 30;
        $report = $funnel->report(new \DateTimeImmutable("-$days days"));
        return $this->render('admin/funnel/index.html.twig', [
            'days' => $days,
            'labels' => Funnel::STEPS,
            'short' => Funnel::SHORT,
            'steps' => $report['steps'],
            'sources' => $report['sources'],
        ]);
    }
}
