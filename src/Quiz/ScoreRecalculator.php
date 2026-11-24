<?php

namespace App\Quiz;

use App\Entity\DogProfile;

/**
 * Works a dog's Bowl Score and daily plan out again after a new weight or body shape, from the
 * coach (ChatAgent's update_dog_profile tool) or the Today screen's log (DashboardController).
 *
 * It runs the quiz's own maths (Scoring, a copy of public/quiz/scoring.js) on the answers saved
 * with the last quiz (ScoreInputs), with today's weight and body shape in place of the quiz's:
 *  - Body shape: the newest logged one.
 *  - A weight newer than that (a later day, or the quiz's day but logged after it): the ideal
 *    weight stays where it was (from the quiz's weight, or the weight logged with that body shape),
 *    and today's body shape is read from how far the new weight is from it (each step is about 10%). So a heavy
 *    dog that slims gets a better body score, and its energy target doesn't shrink as it loses
 *    weight. Puppies keep their logged shape: they are meant to gain.
 *  - Age: the quiz's, plus the time since.
 *
 * Dogs whose quiz was taken before the inputs were saved can't be re-scored (null) until the
 * next quiz. The texts are written again in the quiz's language (QuizTexts).
 */
final class ScoreRecalculator
{
    public function __construct(private readonly QuizTexts $texts)
    {
    }

    /**
     * Re-scores the dog (call flush() after). Returns what changed just now, for the chat:
     * { from, to, plan_from, plan_to, kg, bcs }, or null when nothing changed or it can't re-score.
     */
    public function recalculate(DogProfile $dog, string $why, \DateTimeImmutable $now): ?array
    {
        $in = $this->inputs($dog, $now);
        if ($in === null) {
            return null;
        }
        $r = Scoring::evaluate($in);
        $fields = [
            'quiz_bowl_score' => $r['total'], 'quiz_score_band' => $r['band'], 'quiz_score_capped' => $r['capped'],
            'quiz_ideal_weight_kg' => $r['idealKg'], 'quiz_kcal_calculated' => $r['targetKcal'], 'quiz_kcal_target' => $r['kcal'],
            'quiz_intake_kcal' => $r['intakeKcal'], 'quiz_portion_ratio' => $r['ratio'], 'quiz_plan_keep' => $r['keep'],
            'quiz_top_fix_1' => $r['topFixes'][0] ?? null, 'quiz_top_fix_2' => $r['topFixes'][1] ?? null, 'quiz_top_fix_3' => $r['topFixes'][2] ?? null,
        ];
        foreach ($r['subs'] as $key => $sub) {
            $fields['quiz_sub_' . $key] = $sub['score'];
        }
        $fields += $this->texts->write($dog->getName(), $r, $in, $dog->getLocale());
        $change = $dog->rescore($fields, $r['total'], $why, $now);
        return $change === null ? null : $change + ['kg' => Scoring::roundKg($in['weightKg']), 'bcs' => $in['bcs']];
    }

    /** The quiz's inputs with today's weight, body shape and age (see the class comment). */
    public function inputs(DogProfile $dog, \DateTimeImmutable $now): ?array
    {
        $in = ScoreInputs::fromDog($dog);
        if ($in === null) {
            return null;
        }
        $weights = $dog->getLog('weight');
        $body = $dog->latestLog('body');
        $w = $weights ? end($weights) : null;
        if ($body) {
            $in['bcs'] = (int) round((float) $body['v']);
        }
        if ($w) {
            // Newer than the body shape: a later day, or the same day as the quiz's (logged after it).
            $newer = $body && (strcmp($w['d'], $body['d']) > 0 || ($w['d'] === $body['d'] && ($body['src'] ?? '') === 'quiz' && ($w['src'] ?? '') !== 'quiz'));
            if ($in['stage'] !== 'puppy' && $newer) {
                // The weight that went with that body shape: the quiz's own, or the one logged that day (or before).
                $then = ($body['src'] ?? '') === 'quiz' ? (float) $in['weightKg'] : null;
                foreach ($then === null ? $weights : [] as $e) {
                    if (strcmp($e['d'], $body['d']) <= 0) {
                        $then = (float) $e['v'];
                    }
                }
                if ($then) {
                    $ideal = Scoring::idealWeight($then, (int) round((float) $body['v']));
                    $in['bcs'] = max(1, min(9, (int) round(5 + (100 * (float) $w['v'] / $ideal - 100) / 10)));
                }
            }
            $in['weightKg'] = (float) $w['v'];
        }
        $at = $dog->get('completed_at');
        $taken = \is_string($at) && strtotime($at) ? new \DateTimeImmutable($at) : $dog->getUpdatedAt();
        $in['ageYears'] += max(0, ($now->getTimestamp() - $taken->getTimestamp()) / (365.25 * 86400));
        return $in;
    }
}
