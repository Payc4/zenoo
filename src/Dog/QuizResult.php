<?php

namespace App\Dog;

/**
 * Cleans the result the Bowl Score quiz posts to the site (the same "quiz_*" fields it sends
 * to Klaviyo, built by klaviyoPayload() in public/quiz/app.js). Only quiz_* keys with plain
 * values are kept, text is length-limited and tags are stripped, so the dashboard can show
 * the values safely.
 */
final class QuizResult
{
    public const MAX_KEYS = 80;
    public const MAX_TEXT = 600;

    public static function clean(mixed $raw): ?array
    {
        if (!\is_array($raw)) {
            return null;
        }
        $out = [];
        foreach ($raw as $key => $value) {
            if (\count($out) >= self::MAX_KEYS) {
                break;
            }
            if (!\is_string($key) || !preg_match('/^quiz_[a-z0-9_]{1,40}$/', $key)) {
                continue;
            }
            if (\is_array($value)) {
                $value = array_values(array_filter(array_map([self::class, 'scalar'], \array_slice($value, 0, 20)), fn ($v) => $v !== null));
            } else {
                $value = self::scalar($value);
            }
            $out[$key] = $value;
        }
        // A quiz result always has a dog name and a score.
        if (!isset($out['quiz_dog_name'], $out['quiz_bowl_score']) || !is_numeric($out['quiz_bowl_score'])) {
            return null;
        }
        $out['quiz_dog_name'] = mb_substr((string) $out['quiz_dog_name'], 0, 30);
        return $out;
    }

    private static function scalar(mixed $v): string|int|float|bool|null
    {
        if (\is_bool($v) || \is_int($v) || $v === null) {
            return $v;
        }
        if (\is_float($v)) {
            return is_finite($v) ? round($v, 2) : null;
        }
        if (\is_string($v)) {
            return mb_substr(trim(strip_tags($v)), 0, self::MAX_TEXT);
        }
        return null;
    }
}
