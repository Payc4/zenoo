<?php

namespace App\Quiz;

use App\Entity\DogProfile;

/**
 * The quiz's normalised answers (answers() in public/quiz/app.js), saved with each result inside
 * the answers bundle as "_inputs", so the Bowl Score can be worked out again on the server
 * (ScoreRecalculator). clean() keeps only the fields Scoring::evaluate() reads, with the right
 * types and ranges; anything else, or anything out of range, means "can't recalculate".
 */
final class ScoreInputs
{
    public static function fromDog(DogProfile $dog): ?array
    {
        $answers = $dog->getQuizAnswers();
        return \is_array($answers['_inputs'] ?? null) ? self::clean($answers['_inputs']) : null;
    }

    /**
     * The inputs of a dog saved before they were kept, rebuilt from the fields its quiz saved
     * (klaviyoPayload() in public/quiz/app.js). Estimates: a breed's energy from the activity it
     * was recommended; what each food in the bowl weighs from the energy the dog was eating (split
     * evenly between foods, at typical kcal); typical values for a label read in the quiz.
     */
    public static function rebuild(DogProfile $dog): ?array
    {
        $g = fn (string $k) => $dog->get($k);
        $stage = $g('life_stage');
        $recommended = (int) $g('recommended_activity_band');
        $types = array_values(array_filter((array) $g('food_types'), fn ($t) => isset(Scoring::TYPICAL[$t])));
        $main = isset(Scoring::TYPICAL[$g('food_main')]) ? $g('food_main') : ($types[0] ?? null);
        if (!$main || !$recommended || !$g('weight_kg') || !$g('bcs')) {
            return null;
        }
        $types = $types ?: [$main];
        $treats = isset(Scoring::TREAT_SHARE[$g('treats') ?? '']) ? $g('treats') : 'none';
        $foodKcal = is_numeric($g('intake_kcal')) && is_numeric($g('kcal_calculated'))
            ? (float) $g('intake_kcal') - (float) $g('kcal_calculated') * Scoring::TREAT_SHARE[$treats] : null;
        $feeds = array_map(fn ($t) => ['type' => $t, 'grams' => $foodKcal !== null && $foodKcal > 0
            ? Scoring::jsRound(($foodKcal / \count($types)) * 100 / Scoring::TYPICAL[$t]['kcal100'] / 5) * 5 : null], $types);
        $t = Scoring::TYPICAL[$main];
        return self::clean([
            'diet' => ['pDm' => $g('protein_dm') ?? $t['p'], 'fDm' => $t['f'], 'cDm' => $g('carbs_dm') ?? $t['c'], 'kcalPer100g' => null, 'fromLabel' => (bool) $g('label_given')],
            'foodMain' => $main, 'foodTypes' => $types, 'bcs' => $g('bcs'),
            'breedEnergy' => $stage === 'adult' ? $recommended : min(5, $recommended + 1),
            'level' => $g('activity_band'), 'stage' => $stage, 'appetite' => $g('appetite'), 'signs' => (array) $g('signs'),
            'weightKg' => $g('weight_kg'), 'neutered' => (bool) $g('neutered'), 'ageYears' => $g('age_years'),
            'adultKg' => $g('adult_weight_kg'), 'feeds' => $feeds, 'meals' => $g('meals_per_day'), 'treats' => $treats,
        ]);
    }

    /** The quiz's own answers (app.js's "a") for a dog saved before they were kept, so a retake starts filled in. */
    public static function retakeAnswers(DogProfile $dog): array
    {
        $g = fn (string $k) => $dog->get($k);
        $in = self::fromDog($dog) ?? self::rebuild($dog);
        $a = ['name' => $dog->getName(), 'signs' => (array) ($g('signs') ?? []), 'label' => [], 'labelSkipped' => true, 'amounts' => []];
        if (preg_match('~breed-([a-z0-9-]+)\.jpg~', (string) $g('breed_photo_url'), $m)) {
            $a['breedId'] = $m[1];
        }
        if (is_numeric($g('age_years')) && $g('age_years') >= 1) {
            $a['ageY'] = (string) (int) round((float) $g('age_years'));
        }
        if (\in_array($g('sex'), ['male', 'female'], true)) {
            $a['sex'] = $g('sex');
        }
        if ($g('neutered') !== null) {
            $a['neutered'] = $g('neutered') ? 'yes' : 'no';
        }
        foreach (['weight' => 'weight_kg', 'bcs' => 'bcs', 'foodMain' => 'food_main', 'meals' => 'meals_per_day', 'treats' => 'treats', 'appetite' => 'appetite'] as $key => $field) {
            if ($g($field) !== null) {
                $a[$key] = $key === 'weight' ? (string) $g($field) : $g($field);
            }
        }
        if (is_numeric($g('activity_band'))) {
            $a['actBand'] = min(4, (int) $g('activity_band'));
            $a['actType'] = (int) $g('activity_band') > 4 ? 'sport' : 'walks';
        }
        $a['foods'] = array_values(array_filter((array) ($g('food_types') ?? [$g('food_main')])));
        foreach ($in['feeds'] ?? [] as $f) {
            if ($f['grams'] !== null) {
                $a['amounts'][$f['type']] = ['v' => (string) $f['grams'], 'u' => 'g', 'ns' => false];
            }
        }
        return $a;
    }

    public static function clean(array $in): ?array
    {
        $types = array_keys(Scoring::TYPICAL);
        $num = static fn ($v, float $min, float $max) => is_numeric($v) && (float) $v >= $min && (float) $v <= $max ? (float) $v : null;
        $int = static fn ($v, int $min, int $max) => is_numeric($v) && (int) $v == $v && (int) $v >= $min && (int) $v <= $max ? (int) $v : null;
        $diet = \is_array($in['diet'] ?? null) ? $in['diet'] : [];
        $foodTypes = array_values(array_unique(array_filter((array) ($in['foodTypes'] ?? []), fn ($t) => \in_array($t, $types, true))));
        $feeds = [];
        foreach (\array_slice((array) ($in['feeds'] ?? []), 0, 6) as $f) {
            if (!\is_array($f) || !\in_array($f['type'] ?? null, $types, true)) {
                return null;
            }
            $feeds[] = ['type' => $f['type'], 'grams' => ($f['grams'] ?? null) === null ? null : $num($f['grams'], 0, 20000)];
        }
        $out = [
            'diet' => [
                'pDm' => $num($diet['pDm'] ?? null, 0, 100), 'fDm' => $num($diet['fDm'] ?? null, 0, 100), 'cDm' => $num($diet['cDm'] ?? null, 0, 100),
                'kcalPer100g' => ($diet['kcalPer100g'] ?? null) === null ? null : $num($diet['kcalPer100g'], 1, 1000),
                'fromLabel' => (bool) ($diet['fromLabel'] ?? false),
            ],
            'foodMain' => \in_array($in['foodMain'] ?? null, $types, true) ? $in['foodMain'] : null,
            'foodTypes' => $foodTypes,
            'bcs' => $int($in['bcs'] ?? null, 1, 9),
            'breedEnergy' => $int($in['breedEnergy'] ?? null, 1, 5),
            'level' => $int($in['level'] ?? null, 1, 5),
            'stage' => \in_array($in['stage'] ?? null, ['puppy', 'adult', 'senior'], true) ? $in['stage'] : null,
            'appetite' => \is_string($in['appetite'] ?? null) ? mb_substr($in['appetite'], 0, 20) : null,
            'signs' => array_values(array_map('strval', \array_slice(array_filter((array) ($in['signs'] ?? []), 'is_string'), 0, 10))),
            'weightKg' => $num($in['weightKg'] ?? null, 0.2, 110),
            'neutered' => (bool) ($in['neutered'] ?? false),
            'ageYears' => $num($in['ageYears'] ?? null, 0, 30),
            'adultKg' => ($in['adultKg'] ?? null) === null ? null : $num($in['adultKg'], 0.2, 120),
            'feeds' => $feeds,
            'meals' => \in_array($in['meals'] ?? null, ['1', '2', '3', 'free'], true) ? $in['meals'] : null,
            'treats' => isset(Scoring::TREAT_SHARE[$in['treats'] ?? '']) ? $in['treats'] : 'none',
        ];
        foreach (['foodMain', 'bcs', 'breedEnergy', 'level', 'stage', 'weightKg', 'ageYears'] as $required) {
            if ($out[$required] === null) {
                return null;
            }
        }
        if ($out['diet']['pDm'] === null || $out['diet']['cDm'] === null || !$foodTypes) {
            return null;
        }
        return $out;
    }
}
