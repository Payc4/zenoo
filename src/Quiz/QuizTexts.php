<?php

namespace App\Quiz;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The quiz's result texts, written on the server from config/quiz_codes.json (built from the
 * quiz's content files by bin/build-quiz-codes.js): the band title, the "what to do first" lines
 * and the plan lines. Used when a dog is re-scored after a new weight or body shape
 * (ScoreRecalculator), and to show a re-scored dog's tips in another language (QuizTextExtension).
 *
 * fixVariant() and the plan lines follow resultCopy(), klaviyoPayload() and planWeight() in
 * public/quiz/app.js; keep them in step.
 */
final class QuizTexts
{
    private ?array $codes = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/config/quiz_codes.json')] private readonly string $file,
    ) {
    }

    public function codes(string $locale): ?array
    {
        $this->codes ??= json_decode((string) @file_get_contents($this->file), true) ?: [];
        return $this->codes[$locale] ?? null;
    }

    /** Which "what to do first" line a sub-score gets: "fix", "fixOver", …, or null for none (routine that's fine). */
    public static function fixVariant(string $key, array $r, array $in): ?string
    {
        if ($key === 'routine') {
            $p = $r['subs']['routine']['parts'];
            if ($r['ratio'] === null) {
                return 'fixUnknown';
            }
            if ($p['portion'] < 6 && $r['vetCheck']) {
                return 'fixVet';
            }
            if ($p['portion'] < 6) {
                return $r['ratio'] > 1 ? 'fixOver' : 'fixUnder';
            }
            if ($p['mealPts'] < 2) {
                return $in['stage'] === 'puppy' ? 'fixPuppyMeals' : 'fixMeals';
            }
            if ($p['treatPts'] < 2) {
                return 'fixTreats';
            }
            return null;
        }
        if ($key === 'body' && $in['stage'] === 'puppy') {
            return 'fixPuppy';
        }
        if ($key === 'body' && $r['keep']) {
            return 'fixKeep';
        }
        return 'fix';
    }

    /** {name} etc. as the quiz fills them; {poss} is English only (other languages don't use it). */
    public static function fill(string $template, array $vars): string
    {
        return preg_replace_callback('/\{(\w+)\}/', fn ($m) => (string) ($vars[$m[1]] ?? ''), $template);
    }

    public static function nameVars(string $name, string $locale): array
    {
        $poss = $locale === 'en' ? (preg_match('/s$/i', $name) ? $name . '’' : $name . '’s') : $name;
        return ['name' => $name, 'NAME' => mb_strtoupper($name), 'poss' => $poss, 'POSS' => mb_strtoupper($poss)];
    }

    /** A fix line by its code ("routine.fixVet"), in this language. */
    public function fixText(string $code, string $name, string $locale, array $vars = []): ?string
    {
        [$key, $variant] = array_pad(explode('.', $code, 2), 2, 'fix');
        $t = $this->codes($locale)['variants'][$key][$variant] ?? null;
        return $t === null || $t === '' ? null : self::fill($t, $vars + self::nameVars($name, $locale));
    }

    /**
     * The plan as data (saved as quiz_plan), so its lines can be written in any language
     * (plan()). food_kcal: the food alone; kcal: with the treats it counts in.
     */
    public static function planData(array $r, array $in): array
    {
        $k100 = fn (string $type) => $type === $in['foodMain'] && ($in['diet']['kcalPer100g'] ?? null) ? $in['diet']['kcalPer100g'] : Scoring::TYPICAL[$type]['kcal100'];
        $foodKcal = null;
        if ($r['planFeeds']) {
            $foodKcal = array_sum(array_map(fn ($x) => $x['grams'] * $k100($x['type']) / 100, $r['planFeeds']));
        } elseif ($r['grams']) {
            $foodKcal = $r['grams'] * $k100($in['foodMain']) / 100;
        }
        return [
            'feeds' => $r['planFeeds'] ? array_map(fn ($x) => ['type' => $x['type'], 'grams' => $x['grams']], $r['planFeeds']) : null,
            'grams' => $r['grams'], 'keep' => $r['keep'], 'kcal' => $r['kcal'], 'target' => $r['targetKcal'], 'intake' => $r['intakeKcal'],
            'food_kcal' => $foodKcal === null ? null : (int) (Scoring::jsRound($foodKcal / 10) * 10),
            'ideal' => $r['idealKg'], 'adult' => $r['adultKg'] ?: $in['adultKg'], 'weight' => Scoring::roundKg($in['weightKg']), 'bcs' => $in['bcs'],
            'stage' => $in['stage'], 'treats' => $in['treats'],
            'protein' => isset($r['targets']['proteinG']) ? array_map(fn ($g) => (int) Scoring::jsRound($g), $r['targets']['proteinG']) : null,
            'fat' => isset($r['targets']['fatG']) ? array_map(fn ($g) => (int) Scoring::jsRound($g), $r['targets']['fatG']) : null,
        ];
    }

    /**
     * The plan's lines in this language: plan_food, plan_weight, plan_meals, plan_protein_g, plan_fat_g
     * (keys without "quiz_"). Follows klaviyoPayload() and planWeight() in public/quiz/app.js.
     *
     * @return array<string, ?string>
     */
    public function plan(array $plan, string $name, string $locale): array
    {
        $c = $this->codes($locale) ?? $this->codes('en');
        if ($c === null) {
            return [];
        }
        $P = $c['plan'];
        $vars = ['target' => $plan['target'], 'intake' => $plan['intake'], 'kcal' => $plan['kcal']] + self::nameVars($name, $locale);
        $kg = fn ($v) => str_replace('.', $c['decimalSep'] ?? '.', (string) Scoring::roundKg((float) $v));
        if ($plan['feeds']) {
            $food = implode($P['emailFoodJoin'], array_map(fn ($x) => self::fill($P['emailFood'], ['food' => $c['foods'][$x['type']] ?? $x['type'], 'grams' => $x['grams']]), $plan['feeds']));
        } elseif ($plan['grams']) {
            $food = self::fill($P['gramsText'], ['grams' => $plan['grams']]);
        } else {
            $food = $P['emailNoAmount'];
        }
        if ($plan['keep']) {
            $food .= ' — ' . self::fill($P['keep'][$plan['keep']] ?? '', $vars);
        }
        $weight = $plan['ideal'] === null
            ? self::fill($P['puppyGrowth'], ['adult' => $kg($plan['adult'] ?: $plan['weight'])] + $vars)
            : ($plan['bcs'] === 5 ? self::fill($P['keepWeight'], ['kg' => $kg($plan['weight'])] + $vars) : self::fill($P['aimWeight'], ['kg' => $kg($plan['ideal'])] + $vars));
        $range = fn (?array $g) => $g ? self::fill($P['gramsRange'], ['min' => $g[0], 'max' => $g[1]]) : null;
        return [
            'plan_food' => $food,
            'plan_weight' => $weight,
            'plan_meals' => ($plan['stage'] === 'puppy' ? $P['mealsPuppy'] : $P['mealsAdult']) . (($plan['treats'] ?? 'none') !== 'none' ? $P['treatsCounted'] : ''),
            'plan_protein_g' => $range($plan['protein']),
            'plan_fat_g' => $range($plan['fat']),
        ];
    }

    /**
     * The quiz_* text fields for a result, in this language, and the plan as data (quiz_plan).
     *
     * @return array<string, mixed>
     */
    public function write(string $name, array $r, array $in, string $locale): array
    {
        $c = $this->codes($locale) ?? $this->codes('en');
        if ($c === null) {
            return [];
        }
        $vars = ['kcal' => $r['kcal'], 'target' => $r['targetKcal'], 'intake' => $r['intakeKcal']] + self::nameVars($name, $locale);
        $codes = [];
        $fixes = [];
        foreach ($r['topFixes'] as $key) {
            $variant = self::fixVariant($key, $r, $in);
            $text = $variant ? ($c['variants'][$key][$variant] ?? '') : '';
            if ($text !== '') {
                $codes[] = $key . '.' . $variant;
                $fixes[] = self::fill($text, $vars);
            }
        }
        $plan = self::planData($r, $in);
        $out = [
            'quiz_score_title' => $c['bands'][$r['band']] ?? '',
            'quiz_fix_1_text' => $fixes[0] ?? $c['keepGoing'],
            'quiz_fix_2_text' => $fixes[1] ?? '',
            'quiz_fix_3_text' => $fixes[2] ?? '',
            'quiz_fix_codes' => $codes,
            'quiz_plan' => $plan,
        ];
        foreach ($this->plan($plan, $name, $locale) as $k => $v) {
            $out['quiz_' . $k] = $v;
        }
        return $out;
    }
}
