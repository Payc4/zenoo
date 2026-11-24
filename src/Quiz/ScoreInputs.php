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
