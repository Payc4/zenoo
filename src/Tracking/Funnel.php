<?php

namespace App\Tracking;

use App\Entity\FunnelEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Daily counts of each funnel step per traffic source (Admin → Funnel). Counts only: no IDs, no
 * cookies of its own, so it works with or without analytics consent. GA4 and the Meta pixel get the
 * same events in the browser when the visitor allows them (public/consent.js).
 */
final class Funnel
{
    /** The steps, in funnel order. Browser events (POST /t) must be one of these. */
    public const STEPS = [
        'landing' => 'Visited the site',
        'quiz_start' => 'Started the quiz',
        'quiz_result' => 'Saw the Bowl Score',
        'quiz_submit' => 'Left their email (lead)',
        'account_created' => 'Account created',
        'plan_view' => 'Saw their ZENOO plan',
        'plan_cart' => 'Clicked a starter (to the shop)',
        'chat_message' => 'Messaged the coach',
        'lesson_done' => 'Finished a lesson',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RequestStack $requests,
        private readonly Attribution $attribution,
    ) {
    }

    public const SHORT = ['landing' => 'Visits', 'quiz_start' => 'Quiz', 'quiz_result' => 'Score', 'quiz_submit' => 'Leads', 'account_created' => 'Accounts',
        'plan_view' => 'Plan views', 'plan_cart' => 'Cart clicks', 'chat_message' => 'Coach msgs', 'lesson_done' => 'Lessons'];

    /** Steps also sent to GA4 / the Meta pixel in the browser, with the Meta standard event. */
    public const PIXEL_EVENTS = ['quiz_result' => 'ViewContent', 'quiz_submit' => 'Lead', 'account_created' => 'CompleteRegistration', 'plan_view' => 'ViewContent', 'plan_cart' => 'InitiateCheckout'];

    /** Steps the quiz page sends to the pixel itself (a single page: there's no "next page" to hand them to). */
    private const BROWSER_FIRED = ['quiz_start', 'quiz_result', 'quiz_submit', 'plan_cart'];

    public function record(string $step, ?string $source = null): void
    {
        if (!isset(self::STEPS[$step])) {
            return;
        }
        $request = $this->requests->getMainRequest();
        // Steps that happen on the server reach GA4 / the pixel on the next page the browser shows.
        if ($request && $request->hasSession() && isset(self::PIXEL_EVENTS[$step]) && !\in_array($step, self::BROWSER_FIRED, true)) {
            $pending = $request->getSession()->get('zn_pending_events', []);
            $pending[] = $step;
            $request->getSession()->set('zn_pending_events', \array_slice($pending, -10));
        }
        $source ??= Attribution::label($request ? $this->attribution->get($request) : null);
        $day = new \DateTimeImmutable('today');
        $conn = $this->em->getConnection();
        $updated = $conn->executeStatement('UPDATE funnel_event SET count = count + 1 WHERE day = ? AND name = ? AND source = ?', [$day->format('Y-m-d'), $step, $source]);
        if ($updated === 0) {
            try {
                $conn->insert('funnel_event', ['day' => $day->format('Y-m-d'), 'name' => $step, 'source' => $source, 'count' => 1]);
            } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
                $conn->executeStatement('UPDATE funnel_event SET count = count + 1 WHERE day = ? AND name = ? AND source = ?', [$day->format('Y-m-d'), $step, $source]);
            }
        }
    }

    /** @return array{steps: array<string, int>, sources: array<string, array<string, int>>} totals since $since */
    public function report(\DateTimeImmutable $since): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative('SELECT name, source, SUM(count) AS n FROM funnel_event WHERE day >= ? GROUP BY name, source', [$since->format('Y-m-d')]);
        $steps = array_fill_keys(array_keys(self::STEPS), 0);
        $sources = [];
        foreach ($rows as $r) {
            $steps[$r['name']] = ($steps[$r['name']] ?? 0) + (int) $r['n'];
            $sources[$r['source']][$r['name']] = (int) $r['n'];
        }
        uasort($sources, fn ($a, $b) => ($b['landing'] ?? 0) <=> ($a['landing'] ?? 0));
        return ['steps' => $steps, 'sources' => $sources];
    }
}
