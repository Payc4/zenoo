<?php

namespace App\Quiz;

/**
 * The Bowl Score maths on the server: a line-by-line copy of public/quiz/scoring.js, so a dog's
 * score and plan can be worked out again when its weight or body shape changes (ScoreRecalculator).
 *
 * Keep the two in step: tests/Unit/ScoringParityTest.php runs both on thousands of sample dogs
 * and fails on any difference. Rounding uses jsRound() (JavaScript's Math.round) on purpose.
 *
 * evaluate() takes the quiz's normalised answers (answers() in public/quiz/app.js; cleaned by
 * ScoreInputs) and returns the same fields as the JavaScript, in the same shapes.
 */
final class Scoring
{
    public const TYPICAL = [
        'dry' => ['p' => 28, 'f' => 16, 'c' => 45, 'tier' => 1, 'kcal100' => 370],
        'wet' => ['p' => 40, 'f' => 25, 'c' => 25, 'tier' => 2, 'kcal100' => 95],
        'fresh' => ['p' => 45, 'f' => 20, 'c' => 25, 'tier' => 3, 'kcal100' => 130],
        'home' => ['p' => 40, 'f' => 20, 'c' => 30, 'tier' => 3, 'kcal100' => 140],
        'freezedried' => ['p' => 45, 'f' => 35, 'c' => 8, 'tier' => 4, 'kcal100' => 450],
        'raw' => ['p' => 50, 'f' => 35, 'c' => 5, 'tier' => 4, 'kcal100' => 160],
    ];
    private const K = [1 => 95, 2 => 110, 3 => 130, 4 => 160, 5 => 200];
    private const NEUTERED_FACTOR = 0.9;
    private const GUARD = ['heavyFrom' => 6, 'thinUpTo' => 4, 'maxChange' => 0.2];
    public const TREAT_SHARE = ['none' => 0, 'few' => 0.05, 'several' => 0.1, 'lots' => 0.2];
    private const WEIGHTS = ['protein' => 18, 'carbs' => 18, 'freshness' => 14, 'body' => 16, 'activity' => 14, 'signals' => 10, 'routine' => 10];
    private const BANDS = [[80, 'great'], [60, 'good'], [40, 'look'], [0, 'wins']];
    private const CAPS = ['bodyMild' => 79, 'bodyFar' => 59, 'signsSome' => 79, 'signsMany' => 59];

    /** JavaScript's Math.round: halves go up. */
    public static function jsRound(float $x): float
    {
        return floor($x + 0.5);
    }

    public static function idealWeight(float $kg, int $bcs): float
    {
        return $kg * (100 / (100 + 10 * ($bcs - 5)));
    }

    public static function puppyKcal(float $weightKg, ?float $adultKg, float $ageYears): float
    {
        if (!$adultKg || $adultKg <= $weightKg) {
            return 70 * $weightKg ** 0.75 * ($ageYears < 0.34 ? 3 : 2);
        }
        $p = $weightKg / $adultKg;
        return 130 * $weightKg ** 0.75 * 3.2 * (exp(-0.87 * $p) - 0.1);
    }

    /** @return array{kcal: float, idealKg: ?float, adultKg?: ?float} */
    public static function dailyKcal(array $a): array
    {
        $iw = self::idealWeight($a['weightKg'], $a['bcs']);
        if ($a['stage'] === 'puppy') {
            return ['kcal' => self::puppyKcal($a['weightKg'], $a['adultKg'], $a['ageYears']), 'idealKg' => null, 'adultKg' => $a['adultKg'] ?: null];
        }
        $lvl = $a['level'];
        if ($a['bcs'] >= 7) {
            $lvl = max(1, $lvl - 1);
        }
        if ($a['stage'] === 'senior') {
            $lvl = max(1, $lvl - 1);
        }
        $kcal = self::K[$lvl] * $iw ** 0.75;
        if ($a['neutered']) {
            $kcal *= self::NEUTERED_FACTOR;
        }
        return ['kcal' => $kcal, 'idealKg' => $iw];
    }

    /** @return array{score: int, recommended: int} */
    public static function activityScore(int $breedEnergy, string $stage, int $level): array
    {
        $recommended = $stage === 'adult' ? $breedEnergy : max(1, $breedEnergy - 1);
        $gap = $recommended - $level;
        return ['score' => $gap <= 0 ? 15 : ($gap === 1 ? 10 : ($gap === 2 ? 6 : 3)), 'recommended' => $recommended];
    }

    /** @return array{kcal: float, keep: ?string, vetCheck: bool} */
    public static function guardPlan(float $targetKcal, ?float $nowKcal, int $bcs): array
    {
        $g = self::GUARD;
        if ($nowKcal === null || $nowKcal <= 0) {
            return ['kcal' => $targetKcal, 'keep' => null, 'vetCheck' => false];
        }
        if ($bcs >= $g['heavyFrom'] && $targetKcal > $nowKcal) {
            return ['kcal' => $nowKcal, 'keep' => 'heavy', 'vetCheck' => false];
        }
        if ($bcs <= $g['thinUpTo'] && $targetKcal < $nowKcal) {
            return ['kcal' => $nowKcal, 'keep' => 'thin', 'vetCheck' => false];
        }
        if (abs($targetKcal - $nowKcal) / $nowKcal > $g['maxChange']) {
            return ['kcal' => $nowKcal, 'keep' => 'vet', 'vetCheck' => true];
        }
        return ['kcal' => $targetKcal, 'keep' => null, 'vetCheck' => false];
    }

    public static function roundGrams(float $g): int
    {
        return (int) ($g < 100 ? self::jsRound($g) : self::jsRound($g / 5) * 5);
    }

    public static function roundKg(float $kg): float
    {
        return $kg < 2 ? self::jsRound($kg * 100) / 100 : self::jsRound($kg * 10) / 10;
    }

    /** @param list<array{type: string, grams: ?float}> $feeds */
    public static function intake(array $feeds, ?string $mainType, ?float $mainKcal100, string $treats, float $targetKcal): ?array
    {
        if (!$feeds) {
            return null;
        }
        foreach ($feeds as $x) {
            if ($x['grams'] === null) {
                return null;
            }
        }
        $perFood = [];
        foreach ($feeds as $x) {
            $k100 = $x['type'] === $mainType && $mainKcal100 ? $mainKcal100 : self::TYPICAL[$x['type']]['kcal100'];
            $perFood[] = $x + ['kcal100' => $k100, 'kcal' => ($x['grams'] * $k100) / 100];
        }
        $foodKcal = array_sum(array_column($perFood, 'kcal'));
        $treatKcal = $targetKcal * self::TREAT_SHARE[$treats];
        return ['perFood' => $perFood, 'foodKcal' => $foodKcal, 'treatKcal' => $treatKcal, 'total' => $foodKcal + $treatKcal, 'ratio' => ($foodKcal + $treatKcal) / $targetKcal];
    }

    public static function routineScore(?float $ratio, ?string $meals, ?string $treats, string $stage, int $bcs): array
    {
        $portion = 3;
        if ($ratio !== null) {
            $off = abs($ratio - 1);
            $portion = $off <= 0.1 ? 6 : ($off <= 0.2 ? 4 : ($off <= 0.3 ? 2 : 0));
        }
        if ($stage === 'puppy') {
            $mealPts = $meals === '3' ? 2 : ($meals === '2' ? 1 : 0);
        } else {
            $mealPts = $meals === '2' || $meals === '3' ? 2 : ($meals === '1' ? 1 : ($bcs <= 5 ? 1 : 0));
        }
        $treatPts = ['none' => 2, 'few' => 2, 'several' => 1, 'lots' => 0][$treats ?? ''] ?? 1;
        return ['score' => $portion + $mealPts + $treatPts, 'portion' => $portion, 'mealPts' => $mealPts, 'treatPts' => $treatPts];
    }

    private static function scale(float $raw, float $rawMax, string $key): array
    {
        return ['score' => (int) self::jsRound(($raw / $rawMax) * self::WEIGHTS[$key]), 'max' => self::WEIGHTS[$key]];
    }

    public static function subScores(array $a): array
    {
        $p = $a['diet']['pDm'];
        $prot = $p >= 35 ? 20 : ($p >= 30 ? 16 : ($p >= 25 ? 10 : 5));
        $c = $a['diet']['cDm'];
        $carbs = $c <= 15 ? 20 : ($c <= 25 ? 15 : ($c <= 35 ? 10 : ($c <= 45 ? 6 : 3)));

        $mainTier = self::TYPICAL[$a['foodMain']]['tier'];
        $fresh = [4 => 15, 3 => 12, 2 => 8, 1 => 5][$mainTier];
        $tiers = array_map(fn ($t) => self::TYPICAL[$t]['tier'], $a['foodTypes']);
        if (max($tiers ?: [0]) > $mainTier) {
            $fresh = min(15, $fresh + 2);
        }

        $body = [5 => 20, 4 => 15, 6 => 15, 3 => 10, 7 => 10, 2 => 5, 8 => 5][$a['bcs']] ?? 5;
        $act = self::activityScore($a['breedEnergy'], $a['stage'], $a['level']);

        $signals = 10;
        if ($a['appetite'] === 'picky') {
            $signals -= 3;
        }
        if ($a['appetite'] === 'verypicky') {
            $signals -= 5;
        }
        $signals = max(0, $signals - \count($a['signs']));

        $routine = self::routineScore($a['ratio'] ?? null, $a['meals'], $a['treats'], $a['stage'], $a['bcs']);

        return [
            'protein' => self::scale($prot, 20, 'protein'),
            'carbs' => self::scale($carbs, 20, 'carbs'),
            'freshness' => self::scale($fresh, 15, 'freshness'),
            'body' => self::scale($body, 20, 'body'),
            'activity' => self::scale($act['score'], 15, 'activity') + ['recommended' => $act['recommended']],
            'signals' => self::scale($signals, 10, 'signals'),
            'routine' => self::scale($routine['score'], 10, 'routine') + ['parts' => $routine],
        ];
    }

    public static function band(int $total): string
    {
        foreach (self::BANDS as [$min, $name]) {
            if ($total >= $min) {
                return $name;
            }
        }
        return 'wins';
    }

    public static function nutrientTargets(float $kcal, string $stage): ?array
    {
        if ($stage === 'puppy') {
            return null;
        }
        $pLow = $stage === 'senior' ? 0.35 : 0.30;
        return [
            'proteinG' => [$kcal * $pLow / 3.5, $kcal * 0.45 / 3.5],
            'fatG' => [$kcal * 0.35 / 8.5, $kcal * 0.55 / 8.5],
        ];
    }

    public static function evaluate(array $a): array
    {
        $energy = self::dailyKcal($a);
        $eat = self::intake($a['feeds'] ?? [], $a['foodMain'], $a['diet']['kcalPer100g'] ?? null, $a['treats'] ?: 'none', $energy['kcal']);
        $guard = self::guardPlan($energy['kcal'], $eat ? $eat['total'] : null, $a['bcs']);
        if ($eat && $eat['foodKcal'] > 0 && !$guard['keep'] && abs(($guard['kcal'] - $eat['treatKcal']) / $eat['foodKcal'] - 1) > self::GUARD['maxChange']) {
            $guard = ['kcal' => $eat['total'], 'keep' => 'vet', 'vetCheck' => true];
        }
        $fine = $eat && (($guard['keep'] === 'heavy' && $eat['ratio'] < 1) || ($guard['keep'] === 'thin' && $eat['ratio'] > 1));
        $subs = self::subScores(['ratio' => $eat ? ($fine ? 1.0 : $eat['ratio']) : null] + $a);
        $total = array_sum(array_column($subs, 'score'));
        $signs = \count($a['signs'] ?? []);
        $caps = [];
        if ($a['bcs'] <= 2 || $a['bcs'] >= 8) {
            $caps[] = ['body', self::CAPS['bodyFar']];
        } elseif ($a['bcs'] === 3 || $a['bcs'] === 7) {
            $caps[] = ['body', self::CAPS['bodyMild']];
        }
        if ($signs >= 3) {
            $caps[] = ['signs', self::CAPS['signsMany']];
        } elseif ($signs >= 1) {
            $caps[] = ['signs', self::CAPS['signsSome']];
        }
        $over = array_values(array_filter($caps, fn ($c) => $total > $c[1]));
        usort($over, fn ($x, $y) => $x[1] <=> $y[1]);
        $cap = $over[0] ?? null;
        if ($cap) {
            $total = $cap[1];
        }

        $planFeeds = null;
        if ($eat && $eat['foodKcal'] > 0) {
            $factor = $guard['keep'] ? 1 : ($guard['kcal'] - $eat['treatKcal']) / $eat['foodKcal'];
            $planFeeds = array_map(fn ($x) => ['type' => $x['type'], 'grams' => self::roundGrams($x['grams'] * $factor), 'now' => $x['grams']], $eat['perFood']);
        }
        $k100 = $a['diet']['kcalPer100g'] ?? null;
        $grams = !$eat && $k100 ? self::roundGrams(($energy['kcal'] / $k100) * 100) : null;

        $portionsFirst = $eat && (($a['bcs'] >= 7 && $eat['ratio'] > 1.1) || ($a['bcs'] <= 4 && $eat['ratio'] < 0.9));
        $fixes = [];
        foreach ($subs as $key => $v) {
            if ($v['max'] - $v['score'] > 0) {
                $fixes[] = ['key' => $key, 'gain' => $v['max'] - $v['score']];
            }
        }
        usort($fixes, function ($x, $y) use ($portionsFirst, $guard) {
            $first = $portionsFirst && !$guard['keep'] ? (int) ($y['key'] === 'routine') - (int) ($x['key'] === 'routine') : 0;
            return $first ?: $y['gain'] - $x['gain'];
        });
        $topFixes = array_column(\array_slice($fixes, 0, 3), 'key');

        return [
            'total' => (int) $total, 'band' => self::band((int) $total), 'subs' => $subs, 'capped' => $cap ? $cap[0] : null,
            'kcal' => (int) (self::jsRound($guard['kcal'] / 10) * 10),
            'targetKcal' => (int) (self::jsRound($energy['kcal'] / 10) * 10),
            'keep' => $guard['keep'], 'vetCheck' => $guard['vetCheck'],
            'idealKg' => $energy['idealKg'] === null ? null : self::roundKg($energy['idealKg']),
            'adultKg' => !empty($energy['adultKg']) ? self::roundKg($energy['adultKg']) : null,
            'intakeKcal' => $eat ? (int) (self::jsRound($eat['total'] / 10) * 10) : null,
            'ratio' => $eat ? self::jsRound($eat['ratio'] * 100) / 100 : null,
            'planFeeds' => $planFeeds, 'grams' => $grams,
            'targets' => self::nutrientTargets($guard['kcal'], $a['stage']),
            'topFixes' => $topFixes,
        ];
    }
}
