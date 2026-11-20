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
    /** Typical adult weight by size, for puppies of mixed or unknown breed (as the quiz). */
    public const ADULT_KG = ['toy' => 4, 'small' => 8, 'medium' => 18, 'large' => 33, 'giant' => 55];
    /** Feeding guard, as guardPlan() in the quiz: body shape sets the direction; bigger changes go to the vet. */
    public const HEAVY_FROM = 6;
    public const THIN_UP_TO = 4;
    public const MAX_CHANGE = 0.2;

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
        $w = $dog?->latestLog('weight');
        $b = $dog?->latestLog('body');
        $weight = $num($in['weight_kg'] ?? null) ?? $num($w['v'] ?? null) ?? $num($dog?->getWeightUpdate()['kg'] ?? null) ?? $num($dog?->get('weight_kg'));
        $bcs = (int) ($num($b['v'] ?? null) ?? $num($dog?->get('bcs')) ?? 5);
        // A newer weight without a newer body check: the ideal weight stays what the quiz worked out.
        $newerBody = $b && ($b['src'] ?? 'quiz') !== 'quiz' && (!$w || $b['d'] >= $w['d']);
        $ideal = $newerBody || isset($in['weight_kg']) ? null : $num($dog?->get('ideal_weight_kg'));
        $level = (int) round($num($dog?->get('activity_band')) ?? 3);
        return [
            'weight_kg' => $weight,
            'ideal_kg' => $ideal,
            'bcs' => $bcs,
            'level' => max(1, min(5, $level ?: 3)),
            'neutered' => \in_array($dog?->get('neutered'), [true, 'yes', 1, '1'], true),
            'stage' => (string) ($dog?->get('life_stage') ?: 'adult'),
            'age_years' => $num($dog?->get('age_years')) ?? 3.0,
            'meals' => (int) ($num($dog?->get('meals_per_day')) ?? 2) ?: 2,
            'treats' => (string) ($dog?->get('treats') ?: 'few'),
            // What the dog ate a day at the quiz (food + treats), and the expected adult weight for puppies.
            'intake_kcal' => $newerBody ? null : $num($dog?->get('intake_kcal')),
            'adult_kg' => $num($dog?->get('adult_weight_kg')) ?? (self::ADULT_KG[(string) $dog?->get('breed_size')] ?? null),
        ];
    }

    /**
     * The day's energy for the plan: the calculated need, held at today's intake when body shape
     * says not to go that way (heavy: never more, thin: never less), or when the change would be
     * more than 20% (then: check the amounts with the vet). Same as guardPlan() in the quiz.
     *
     * @return array{kcal: float, calculated: float, ideal_kg: ?float, keep: ?string}
     */
    public static function planKcal(array $f): array
    {
        $e = self::dailyKcal((float) $f['weight_kg'], $f['bcs'], $f['level'], $f['neutered'], $f['stage'], $f['age_years'], $f['ideal_kg'], $f['adult_kg'] ?? null);
        $now = $f['intake_kcal'] ?? null;
        $keep = null;
        if ($now && $now > 0) {
            if ($f['bcs'] >= self::HEAVY_FROM && $e['kcal'] > $now) {
                $keep = 'heavy';
            } elseif ($f['bcs'] <= self::THIN_UP_TO && $e['kcal'] < $now) {
                $keep = 'thin';
            } elseif (abs($e['kcal'] - $now) / $now > self::MAX_CHANGE) {
                $keep = 'vet';
            }
        }
        return ['kcal' => $keep ? (float) $now : $e['kcal'], 'calculated' => $e['kcal'], 'ideal_kg' => $e['ideal_kg'], 'keep' => $keep];
    }

    public static function idealWeight(float $kg, int $bcs): float
    {
        return $kg * (100 / (100 + 10 * ($bcs - 5)));
    }

    /** Same as dailyKcal() in the quiz. */
    public static function dailyKcal(float $weightKg, int $bcs, int $level, bool $neutered, string $stage, float $ageYears, ?float $idealKg = null, ?float $adultKg = null): array
    {
        $ideal = $idealKg ?? self::idealWeight($weightKg, $bcs);
        if ($stage === 'puppy') {
            // Growing (NRC 2006): from today's and the expected adult weight. No target weight.
            $kcal = $adultKg && $adultKg > $weightKg
                ? 130 * $weightKg ** 0.75 * 3.2 * (exp(-0.87 * $weightKg / $adultKg) - 0.1)
                : 70 * $weightKg ** 0.75 * ($ageYears < 0.34 ? 3 : 2);
            return ['kcal' => $kcal, 'ideal_kg' => null];
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
        self::needWeight($f);
        $r = self::planKcal($f);
        $treats = $r['kcal'] * (self::TREAT_SHARE[$f['treats']] ?? 0.05);
        return [
            'kcal_per_day' => (int) round($r['kcal'], -1),
            'ideal_weight_kg' => $r['ideal_kg'] === null ? null : self::roundKg($r['ideal_kg']),
            'treats_kcal_within_that' => (int) round($treats, -1),
            'based_on' => sprintf('%s kg, body score %d/9, activity %d/5, %s, %s', $f['weight_kg'], $f['bcs'], $f['level'], $f['neutered'] ? 'neutered' : 'not neutered', $f['stage'])
                . ($f['bcs'] >= 7 ? '; set for slow weight loss' : ''),
            'note' => self::keepNote($r, $f),
        ];
    }

    private static function portion(array $in, ?DogProfile $dog): array
    {
        $f = self::dogFacts($dog, $in);
        self::needWeight($f);
        $energy = self::planKcal($f);
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
            'note' => 'Weigh it on a kitchen scale. ' . self::keepNote($energy, $f),
        ];
    }

    private static function weightPlan(array $in, ?DogProfile $dog): array
    {
        $f = self::dogFacts($dog, $in);
        $now = self::needWeight($f);
        if ($f['stage'] === 'puppy' && !is_numeric($in['target_kg'] ?? null)) {
            return ['current_kg' => $now, 'expected_adult_kg' => $f['adult_kg'] ? self::roundKg($f['adult_kg']) : null,
                'note' => 'A growing puppy has no target weight: weigh weekly and expect steady gain. Don\'t plan weight loss for a puppy without the vet.'];
        }
        $target = is_numeric($in['target_kg'] ?? null) ? (float) $in['target_kg'] : ($f['ideal_kg'] ?? self::idealWeight($now, $f['bcs']));
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
            $out[] = ['days' => $from === $to ? "day $from" : "days {$from}–{$to}", 'from' => $from, 'to' => $to, 'old_food_percent' => 100 - $new, 'new_food_percent' => $new];
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

    /** What to tell the pet parent about the plan's energy (see planKcal()). */
    private static function keepNote(array $r, array $f): string
    {
        return match ($r['keep']) {
            'heavy' => sprintf('Held at what the dog eats now (%d kcal; the formula says %d): it is above ideal shape, so never advise MORE than today. Weigh every 2 weeks; if the weight doesn\'t drop, cut about 10%%.', round($r['kcal'], -1), round($r['calculated'], -1)),
            'thin' => sprintf('Held at what the dog eats now (%d kcal; the formula says %d): it is below ideal shape, so never advise LESS than today. Weigh every 2 weeks; if there\'s no gain, add about 10%%.', round($r['kcal'], -1), round($r['calculated'], -1)),
            'vet' => sprintf('The formula (%d kcal) is more than 20%% away from what the dog eats now (%d kcal). Don\'t give a new amount: say to keep today\'s amounts and check them with the vet.', round($r['calculated'], -1), round($r['kcal'], -1)),
            default => $f['stage'] === 'puppy'
                ? 'A growing puppy: weigh weekly and expect steady gain; adjust with the vet as it grows.'
                : 'A starting point: check the weight every 2 weeks and adjust by about 10%.',
        };
    }

    /** Two decimals below 2 kg, one above (as the quiz). */
    public static function roundKg(float $kg): float
    {
        return $kg < 2 ? round($kg, 2) : round($kg, 1);
    }

    private static function roundGrams(float $g): int
    {
        return (int) ($g >= 100 ? round($g / 5) * 5 : round($g));
    }
}
