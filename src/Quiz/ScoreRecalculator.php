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
 *  - What the owner told the coach since (food, amounts, meals, treats, activity, symptoms,
 *    appetite: DogProfile::getProgress()), over the quiz's answers.
 *
 * Dogs saved before the inputs were kept get them rebuilt from their saved result (ScoreInputs::
 * rebuild()). The texts are written again in the quiz's language, and the plan saved as data so
 * it can be shown in any language (QuizTexts).
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
        // The profile shows (and the coach reads) today's answers, not only the quiz's.
        $fields += [
            'quiz_food_main' => $in['foodMain'], 'quiz_food_types' => $in['foodTypes'], 'quiz_label_given' => $in['diet']['fromLabel'],
            'quiz_protein_dm' => (int) Scoring::jsRound($in['diet']['pDm']), 'quiz_carbs_dm' => (int) Scoring::jsRound($in['diet']['cDm']),
            'quiz_meals_per_day' => $in['meals'], 'quiz_treats' => $in['treats'], 'quiz_appetite' => $in['appetite'],
            'quiz_signs' => $in['signs'], 'quiz_top_sign' => $in['signs'][0] ?? null, 'quiz_activity_band' => $in['level'],
        ];
        $fields += $this->texts->write($dog->getName(), $r, $in, $dog->getLocale());
        if (!empty($in['targetKg'])) {
            $fields['quiz_target_weight_kg'] = round((float) $in['targetKg'], 2);
        }
        $change = $dog->rescore($fields, $r['total'], $why, $now);
        return $change === null ? null : $change + ['kg' => Scoring::roundKg($in['weightKg']), 'bcs' => $in['bcs']];
    }

    /**
     * What the owner told the coach since the quiz (DogProfile::getProgress()), over the quiz's answers.
     * A new main food without amounts takes the old main food's place in the bowl, amount unknown.
     */
    public static function withProgress(array $in, array $progress): ?array
    {
        $v = fn (string $k) => $progress[$k]['v'] ?? null;
        foreach (['level', 'meals', 'treats', 'appetite', 'signs'] as $k) {
            if ($v($k) !== null) {
                $in[$k] = $v($k);
            }
        }
        $oldMain = $in['foodMain'];
        if (\is_array($food = $v('food'))) {
            $in['foodMain'] = $food['type'];
            $in['diet'] = $food['diet'];
        }
        $feedsFirst = \is_array($v('feeds')) && \is_array($v('food')) && strcmp($progress['feeds']['d'], $progress['food']['d']) < 0;
        if (\is_array($v('feeds'))) {
            $in['feeds'] = $v('feeds');
        }
        $types = array_column($in['feeds'], 'type');
        if (!\in_array($in['foodMain'], $types, true)) {
            $i = array_search($oldMain, $types, true);
            if ($i !== false && (!$v('feeds') || $feedsFirst)) {
                $in['feeds'][$i] = ['type' => $in['foodMain'], 'grams' => null];
            } elseif ($in['feeds']) {
                $in['foodMain'] = $in['feeds'][0]['type']; // the bowl they described last wins
                if ($in['foodMain'] !== (\is_array($food) ? $food['type'] : $oldMain)) { // no label for this one: typical values
                    $in['diet'] = ['pDm' => Scoring::TYPICAL[$in['foodMain']]['p'], 'fDm' => Scoring::TYPICAL[$in['foodMain']]['f'], 'cDm' => Scoring::TYPICAL[$in['foodMain']]['c'], 'kcalPer100g' => null, 'fromLabel' => false];
                }
            } else {
                $in['feeds'] = [['type' => $in['foodMain'], 'grams' => null]];
            }
        }
        $in['foodTypes'] = array_values(array_unique(array_column($in['feeds'], 'type')));
        return ScoreInputs::clean($in);
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
        $kg = $w ? (float) $w['v'] : (float) $in['weightKg'];
        $target = null;
        if ($in['stage'] === 'puppy') {
            // Puppies keep their logged shape: they are meant to gain.
            if ($body) {
                $in['bcs'] = (int) round((float) $body['v']);
            }
        } else {
            // The target weight is settled once (the quiz's weight and shape) and kept, so it doesn't
            // drift each time a weight and shape are estimated again (36.1 kg at 7/9 is "about 30 kg",
            // 38 kg at 8/9 "about 29 kg"). Today's shape is read from how far the weight is from it,
            // each step about 10%. A logged shape two or more steps away from that is a real correction
            // (the vet's, or a misjudged quiz answer), and the target moves with it.
            $target = (float) ($dog->get('target_weight_kg') ?: Scoring::idealWeight((float) $in['weightKg'], (int) $in['bcs']));
            $shape = fn (float $t) => max(1, min(9, (int) round(5 + (100 * $kg / $t - 100) / 10)));
            $in['bcs'] = $shape($target);
            if ($body && ($body['src'] ?? '') !== 'quiz' && abs((int) round((float) $body['v']) - $in['bcs']) >= 2) {
                $in['bcs'] = (int) round((float) $body['v']);
                $target = Scoring::idealWeight($kg, $in['bcs']);
            }
        }
        $in['weightKg'] = $kg;
        $in = self::withProgress($in, $dog->getProgress());
        if ($in === null) {
            return null;
        }
        if ($target) {
            $in['targetKg'] = $target;
        }
        $at = $dog->get('completed_at');
        $taken = \is_string($at) && strtotime($at) ? new \DateTimeImmutable($at) : $dog->getUpdatedAt();
        $in['ageYears'] += max(0, ($now->getTimestamp() - $taken->getTimestamp()) / (365.25 * 86400));
        return $in;
    }
}
