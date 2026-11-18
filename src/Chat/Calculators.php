<?php

namespace App\Chat;

use App\Entity\DogProfile;

/**
 * Fixed maths for the coach (the "calculate" tool). The model explains numbers; it never makes
 * them up. The formulas mirror the Bowl Score quiz (public/quiz/scoring.js), so the coach and the
 * quiz always agree.
 */
final class Calculators
{
    /** Typical energy as fed (kcal per 100 g) when there's no label, as in the quiz. */
    public const TYPICAL_KCAL_100G = ['dry' => 370, 'wet' => 95, 'fresh' => 130, 'home' => 140, 'freezedried' => 450, 'raw' => 160];
    /** kcal per kg^0.75 by activity level 1–5. */
    private const K = [1 => 95, 2 => 110, 3 => 130, 4 => 160, 5 => 200];
    private const TREAT_SHARE = ['none' => 0, 'few' => 0.05, 'several' => 0.1, 'lots' => 0.2];

    public static function tool(): array
    {
        return [
            'name' => 'calculate',
            'description' => 'ZENOO\'s fixed calculators, the same maths as the Bowl Score quiz. Use it for EVERY number you give about this dog '
                . '(calories, grams, target weight, weeks, switching days, cost) instead of working it out yourself. '
                . 'Leave out the dog\'s details: they come from the profile (including a newer weight they told you). '
                . 'Operations: daily_energy (calories a day and ideal weight); portion (grams a day and per meal for a food: give kcal_per_100g from the pack, '
                . 'or the label values, or a food_type for a typical value; share for a topper or half-bowl, e.g. 0.25); weight_plan (safe weekly loss and how long, '
                . 'to target_kg or the ideal weight); switch_schedule (day-by-day old/new food split; days 7, 10 or 14); cost_per_day (from grams_per_day, pack_grams, pack_price); '
                . 'label_energy (kcal per 100 g, carbs and dry matter from the label\'s analytical constituents).',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'operation' => ['type' => 'string', 'enum' => ['daily_energy', 'portion', 'weight_plan', 'switch_schedule', 'cost_per_day', 'label_energy']],
                    'weight_kg' => ['type' => 'number', 'description' => 'Only to override the profile.'],
                    'target_kg' => ['type' => 'number'],
                    'kcal_per_100g' => ['type' => 'number'],
                    'food_type' => ['type' => 'string', 'enum' => array_keys(self::TYPICAL_KCAL_100G)],
                    'label' => [
                        'type' => 'object',
                        'description' => 'Percent as fed, from "analytical constituents".',
                        'properties' => ['protein' => ['type' => 'number'], 'fat' => ['type' => 'number'], 'fibre' => ['type' => 'number'], 'ash' => ['type' => 'number'], 'moisture' => ['type' => 'number']],
                    ],
                    'share' => ['type' => 'number', 'description' => 'Part of the day\'s calories from this food, 0–1 (default 1).'],
                    'meals' => ['type' => 'integer'],
                    'days' => ['type' => 'integer', 'enum' => [7, 10, 14]],
                    'grams_per_day' => ['type' => 'number'],
                    'pack_grams' => ['type' => 'number'],
                    'pack_price' => ['type' => 'number', 'description' => 'In euros.'],
                ],
                'required' => ['operation'],
            ],
        ];
    }

    /** Runs one operation. Returns the result for the model, or ['error' => …]. */
    public static function run(array $in, ?DogProfile $dog): array
    {
        try {
            return match ($in['operation'] ?? '') {
                'daily_energy' => self::dailyEnergy($in, $dog),
                'portion' => self::portion($in, $dog),
                'weight_plan' => self::weightPlan($in, $dog),
                'switch_schedule' => self::switchSchedule((int) ($in['days'] ?? 7)),
                'cost_per_day' => self::costPerDay($in),
                'label_energy' => self::labelEnergy((array) ($in['label'] ?? [])),
                default => ['error' => 'Unknown operation.'],
            };
        } catch (\InvalidArgumentException $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /** The dog's facts the maths needs: the quiz, with a newer weight from the chat on top. */
    public static function dogFacts(?DogProfile $dog, array $in = []): array
    {
        $num = static fn ($v) => is_numeric($v) ? (float) $v : null;
        $weight = $num($in['weight_kg'] ?? null) ?? $num($dog?->getWeightUpdate()['kg'] ?? null) ?? $num($dog?->get('weight_kg'));
        $level = (int) round($num($dog?->get('activity_band')) ?? 3);
        return [
            'weight_kg' => $weight,
            'bcs' => (int) ($num($dog?->get('bcs')) ?? 5),
            'level' => max(1, min(5, $level ?: 3)),
            'neutered' => \in_array($dog?->get('neutered'), [true, 'yes', 1, '1'], true),
            'stage' => (string) ($dog?->get('life_stage') ?: 'adult'),
            'age_years' => $num($dog?->get('age_years')) ?? 3.0,
            'meals' => (int) ($num($dog?->get('meals_per_day')) ?? 2) ?: 2,
            'treats' => (string) ($dog?->get('treats') ?: 'few'),
        ];
    }

    public static function idealWeight(float $kg, int $bcs): float
    {
        return $kg * (100 / (100 + 10 * ($bcs - 5)));
    }

    /** Same as dailyKcal() in the quiz. */
    public static function dailyKcal(float $weightKg, int $bcs, int $level, bool $neutered, string $stage, float $ageYears): array
    {
        $ideal = self::idealWeight($weightKg, $bcs);
        if ($stage === 'puppy') {
            return ['kcal' => 70 * $weightKg ** 0.75 * ($ageYears < 0.34 ? 3 : 2), 'ideal_kg' => $weightKg];
        }
        if ($bcs >= 7) {
            $level = max(1, $level - 1); // weight-loss goal
        }
        if ($stage === 'senior') {
            $level = max(1, $level - 1);
        }
        $kcal = self::K[$level] * $ideal ** 0.75 * ($neutered ? 0.9 : 1);
        return ['kcal' => $kcal, 'ideal_kg' => $ideal];
    }

    private static function needWeight(array $f): float
    {
        if (!$f['weight_kg'] || $f['weight_kg'] <= 0) {
            throw new \InvalidArgumentException('The dog\'s weight isn\'t known: ask for it first.');
        }
        return $f['weight_kg'];
    }

    private static function dailyEnergy(array $in, ?DogProfile $dog): array
    {
        $f = self::dogFacts($dog, $in);
        $r = self::dailyKcal(self::needWeight($f), $f['bcs'], $f['level'], $f['neutered'], $f['stage'], $f['age_years']);
        $treats = $r['kcal'] * (self::TREAT_SHARE[$f['treats']] ?? 0.05);
        return [
            'kcal_per_day' => (int) round($r['kcal'], -1),
            'ideal_weight_kg' => round($r['ideal_kg'], 1),
            'treats_kcal_within_that' => (int) round($treats, -1),
            'based_on' => sprintf('%s kg, body score %d/9, activity %d/5, %s, %s', $f['weight_kg'], $f['bcs'], $f['level'], $f['neutered'] ? 'neutered' : 'not neutered', $f['stage'])
                . ($f['bcs'] >= 7 ? '; set for slow weight loss' : ''),
            'note' => 'A starting point: check the weight every 2 weeks and adjust by about 10%.',
        ];
    }

    private static function portion(array $in, ?DogProfile $dog): array
    {
        $f = self::dogFacts($dog, $in);
        $energy = self::dailyKcal(self::needWeight($f), $f['bcs'], $f['level'], $f['neutered'], $f['stage'], $f['age_years']);
        if (is_numeric($in['kcal_per_100g'] ?? null) && $in['kcal_per_100g'] > 0) {
            $k100 = (float) $in['kcal_per_100g'];
            $source = 'the pack\'s energy value';
        } elseif (!empty($in['label'])) {
            $k100 = self::labelEnergy((array) $in['label'])['kcal_per_100g'];
            $source = 'the label (estimated energy)';
        } else {
            $type = (string) ($in['food_type'] ?? $dog?->get('food_main') ?? 'dry');
            $k100 = self::TYPICAL_KCAL_100G[$type] ?? throw new \InvalidArgumentException('Unknown food type.');
            $source = 'a typical ' . $type . ' food (no label given)';
        }
        $share = is_numeric($in['share'] ?? null) ? max(0.05, min(1, (float) $in['share'])) : 1.0;
        $treatShare = self::TREAT_SHARE[$f['treats']] ?? 0.05;
        $kcal = $energy['kcal'] * (1 - $treatShare) * $share;
        $grams = $kcal / $k100 * 100;
        $meals = max(1, min(4, (int) ($in['meals'] ?? $f['meals'])));
        return [
            'grams_per_day' => self::roundGrams($grams),
            'meals' => $meals,
            'grams_per_meal' => self::roundGrams($grams / $meals),
            'kcal_from_this_food' => (int) round($kcal, -1),
            'share_of_day' => $share,
            'energy_value_used' => round($k100) . ' kcal per 100 g, from ' . $source,
            'treats_allowance_kcal' => (int) round($energy['kcal'] * $treatShare, -1),
            'note' => 'Weigh it on a kitchen scale. Check the weight every 2 weeks and adjust by about 10%.',
        ];
    }

    private static function weightPlan(array $in, ?DogProfile $dog): array
    {
        $f = self::dogFacts($dog, $in);
        $now = self::needWeight($f);
        $target = is_numeric($in['target_kg'] ?? null) ? (float) $in['target_kg'] : self::idealWeight($now, $f['bcs']);
        if ($target >= $now) {
            return ['current_kg' => $now, 'target_kg' => round($target, 1), 'note' => 'No weight loss needed for this target.'];
        }
        $lose = $now - $target;
        $slow = $now * 0.01;
        $fast = $now * 0.02;
        return [
            'current_kg' => $now,
            'target_kg' => round($target, 1),
            'to_lose_kg' => round($lose, 1),
            'safe_loss_per_week_kg' => [round($slow, 2), round($fast, 2)],
            'weeks' => [(int) ceil($lose / $fast), (int) ceil($lose / $slow)],
            'note' => '1–2% of body weight a week. Weigh every 2 weeks; agree the plan with the vet, especially for a big gap or a dog with health issues.',
        ];
    }

    private static function switchSchedule(int $days): array
    {
        $days = \in_array($days, [7, 10, 14], true) ? $days : 7;
        $steps = [25, 50, 75, 100];
        $per = $days / 4;
        $out = [];
        foreach ($steps as $i => $new) {
            $from = (int) round($i * $per) + 1;
            $to = (int) round(($i + 1) * $per);
            $out[] = ['days' => $from === $to ? "day $from" : "days {$from}–{$to}", 'old_food_percent' => 100 - $new, 'new_food_percent' => $new];
        }
        return ['schedule' => $out, 'note' => 'Go back a step for a couple of days if the stool gets soft. Sensitive tummies: use 14 days.'];
    }

    private static function costPerDay(array $in): array
    {
        $g = (float) ($in['grams_per_day'] ?? 0);
        $pack = (float) ($in['pack_grams'] ?? 0);
        $price = (float) ($in['pack_price'] ?? 0);
        if ($g <= 0 || $pack <= 0 || $price <= 0) {
            throw new \InvalidArgumentException('Needs grams_per_day, pack_grams and pack_price.');
        }
        return [
            'cost_per_day_eur' => round($price / $pack * $g, 2),
            'cost_per_30_days_eur' => round($price / $pack * $g * 30, 2),
            'days_per_pack' => round($pack / $g, 1),
        ];
    }

    /** Modified Atwater from the label, carbs by difference, and dry matter (as in the quiz). */
    public static function labelEnergy(array $l): array
    {
        $p = (float) ($l['protein'] ?? 0);
        $fat = (float) ($l['fat'] ?? 0);
        $fi = (float) ($l['fibre'] ?? 0);
        $a = (float) ($l['ash'] ?? 0);
        $m = (float) ($l['moisture'] ?? 0);
        if ($p <= 0 || $p + $fat + $fi + $a + $m > 100) {
            throw new \InvalidArgumentException('Those label values don\'t add up: check protein, fat, fibre, ash and moisture.');
        }
        $carbs = max(0, 100 - $p - $fat - $fi - $a - $m);
        $dm = 100 - $m;
        $toDm = static fn ($x) => $dm > 0 ? round($x / $dm * 100, 1) : 0;
        return [
            'kcal_per_100g' => round(3.5 * $p + 8.5 * $fat + 3.5 * $carbs),
            'carbs_as_fed_percent' => round($carbs, 1),
            'dry_matter_percent' => ['protein' => $toDm($p), 'fat' => $toDm($fat), 'carbs' => $toDm($carbs), 'fibre' => $toDm($fi)],
            'note' => 'Estimated energy; the pack\'s own kcal value is better if it gives one.',
        ];
    }

    private static function roundGrams(float $g): int
    {
        return (int) ($g >= 100 ? round($g / 5) * 5 : round($g));
    }
}
