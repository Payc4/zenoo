<?php

namespace App\Controller\Admin;

use App\Entity\DogProfile;
use App\Entity\QuizSubmission;
use App\Tracking\Attribution;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Roadmap 12, back office: everyone who took the quiz, newest first. Pet parents with an account
 * (their dog, score, source, plan clicks) and leads who haven't confirmed their email yet (kept for
 * the link's 7 days). Download as CSV for the team.
 */
#[IsGranted('ROLE_ADMIN')]
final class LeadsController extends AbstractController
{
    #[Route('/admin/leads', name: 'admin_leads', methods: ['GET'])]
    public function index(Request $request, EntityManagerInterface $em): Response
    {
        $rows = [];
        foreach ($em->getRepository(DogProfile::class)->findBy([], ['updatedAt' => 'DESC'], 2000) as $dog) {
            $u = $dog->getUser();
            $rows[] = [
                'status' => 'account', 'email' => $u->getEmail(), 'name' => $u->getFirstName(), 'locale' => $dog->getLocale(),
                'dog' => $dog->getName(), 'breed' => (string) $dog->get('breed', ''), 'score' => $dog->getBowlScore(),
                'source' => Attribution::label($u->getAttribution()), 'date' => $dog->getUpdatedAt(),
                'plan_clicks' => array_sum(array_filter($dog->getPlanClicks(), 'is_int')),
                'marketing' => $dog->get('marketing_consent') ? 'yes' : '',
            ];
        }
        foreach ($em->getRepository(QuizSubmission::class)->findBy(['confirmedAt' => null], ['createdAt' => 'DESC'], 2000) as $s) {
            $d = $s->getData();
            $rows[] = [
                'status' => 'lead', 'email' => $s->getEmail(), 'name' => $s->getFirstName(), 'locale' => $s->getLocale(),
                'dog' => $s->getDogName(), 'breed' => (string) ($d['quiz_breed'] ?? ''), 'score' => (int) ($d['quiz_bowl_score'] ?? 0),
                'source' => Attribution::label($d['_attribution'] ?? null), 'date' => $s->getCreatedAt(), 'plan_clicks' => 0,
                'marketing' => !empty($d['quiz_marketing_consent']) ? 'yes' : '',
            ];
        }
        usort($rows, fn ($a, $b) => $b['date'] <=> $a['date']);

        if ($request->query->get('format') === 'csv') {
            $out = fopen('php://temp', 'r+');
            fputcsv($out, ['status', 'email', 'first_name', 'language', 'dog', 'breed', 'bowl_score', 'source', 'date', 'plan_clicks', 'marketing_consent']);
            foreach ($rows as $r) {
                fputcsv($out, [$r['status'], $r['email'], $r['name'], $r['locale'], $r['dog'], $r['breed'], $r['score'], $r['source'], $r['date']->format('Y-m-d H:i'), $r['plan_clicks'], $r['marketing']]);
            }
            rewind($out);
            return new Response("\u{FEFF}" . stream_get_contents($out), 200, [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="zenoo-leads-' . date('Y-m-d') . '.csv"',
            ]);
        }
        return $this->render('admin/leads/index.html.twig', ['rows' => $rows]);
    }
}
