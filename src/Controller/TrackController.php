<?php

namespace App\Controller;

use App\Tracking\Funnel;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Funnel steps that happen in the browser (the quiz runs there). Once per visit per step; counts only. */
final class TrackController extends AbstractController
{
    private const BROWSER_STEPS = ['quiz_start', 'quiz_result', 'plan_cart'];

    #[Route('/t', name: 'app_track', methods: ['POST'])]
    public function track(Request $request, Funnel $funnel): Response
    {
        $step = (string) (json_decode($request->getContent(), true)['e'] ?? $request->request->get('e', ''));
        if (\in_array($step, self::BROWSER_STEPS, true) && $request->hasSession()) {
            $seen = $request->getSession()->get('funnel_seen', []);
            if (!\in_array($step, $seen, true)) {
                $seen[] = $step;
                $request->getSession()->set('funnel_seen', $seen);
                $funnel->record($step);
            }
        }
        return new Response('', 204);
    }
}
