<?php

namespace App\Dog;

use App\Entity\Course;
use App\Entity\DogProfile;
use App\Entity\Lesson;

/**
 * Picks the three course lessons that fit a dog best, from its quiz answers.
 *
 * Each rule below is one concern from the quiz (weight, a sign, a low part of the score...)
 * with a weight, the lesson NUMBERS that help most (1–30, the order of the ZENOO Dog Wellness
 * Course, the same in English and Lithuanian) and a short reason shown on the dashboard.
 * The heaviest concerns are served first, one lesson each, skipping finished lessons and
 * lessons already picked. If the quiz gives few clues, the first unfinished lessons fill in.
 */
final class LessonRecommender
{
    /**
     * @param array<int, bool> $completedIds lesson id => finished
     * @return list<array{lesson: Lesson, number: int, reason: string}>
     */
    public function recommend(DogProfile $dog, Course $course, array $completedIds = [], int $count = 3): array
    {
        $byNumber = [];
        $n = 0;
        foreach ($course->getLessons() as $lesson) {
            $n++;
            if ($lesson->isPublished()) {
                $byNumber[$n] = $lesson;
            }
        }

        // One lesson per concern, most important concern first, so the three picks cover
        // different things (e.g. weight, then the loose stools, then the itching).
        $rules = $this->rules($dog);
        usort($rules, fn ($x, $y) => $y[1] <=> $x[1]);
        $picks = [];
        foreach ($rules as [$numbers, , $reason]) {
            if (\count($picks) >= $count) {
                break;
            }
            foreach ($numbers as $number) {
                $lesson = $byNumber[$number] ?? null;
                if ($lesson && !isset($picks[$number]) && empty($completedIds[$lesson->getId()])) {
                    $picks[$number] = ['lesson' => $lesson, 'number' => $number, 'reason' => $reason];
                    break;
                }
            }
        }
        foreach ($byNumber as $number => $lesson) {
            if (\count($picks) >= $count) {
                break;
            }
            if (!isset($picks[$number]) && empty($completedIds[$lesson->getId()])) {
                $picks[$number] = ['lesson' => $lesson, 'number' => $number, 'reason' => 'A good next step in the course'];
            }
        }
        return array_values($picks);
    }

    /** @return list<array{list<int>, int, string}> [lesson numbers, weight, reason] */
    private function rules(DogProfile $dog): array
    {
        $rules = [];
        $stage = (string) $dog->get('life_stage', '');
        $bcs = (int) $dog->get('bcs', 5);
        $signs = (array) $dog->get('signs', []);
        $sub = fn (string $k) => (int) $dog->get('sub_' . $k, DogProfile::SUB_MAX[$k]) / DogProfile::SUB_MAX[$k];
        $food = (string) $dog->get('food_main', '');

        if ($stage === 'puppy') {
            $rules[] = [[20, 2], 30, 'For a growing puppy'];
        }
        if ($stage === 'senior') {
            $rules[] = [[21, 13, 28], 30, 'For a senior dog'];
        }
        if ($bcs >= 6) {
            $rules[] = [[15, 13], 28, 'To reach a healthy weight'];
        } elseif ($bcs <= 3) {
            $rules[] = [[16, 2], 28, 'To build healthy weight and muscle'];
        }
        $signRules = [
            'stools' => [[1, 3, 7], 26, 'For the loose stools you mentioned'],
            'gas' => [[3, 1, 11], 24, 'For the gas and bloating you mentioned'],
            'itching' => [[8, 9], 24, 'For the itching you mentioned'],
            'tears' => [[8, 12], 18, 'For the tear stains you mentioned'],
            'coat' => [[9, 12], 22, 'For a shinier coat'],
            'energy' => [[2, 28], 20, 'For more energy'],
            'breath' => [[29, 30], 12, 'For fresher breath'],
        ];
        foreach ($signs as $sign) {
            if (isset($signRules[$sign])) {
                $rules[] = $signRules[$sign];
            }
        }
        if ($sub('protein') < 0.6 || $sub('carbs') < 0.6) {
            $rules[] = [[2, 5], 22, 'To get more protein and fewer carbs in the bowl'];
        }
        if ($sub('freshness') < 0.6) {
            $rules[] = [[5, 7], 20, 'For a fresher bowl'];
        }
        if ($food === 'home') {
            $rules[] = [[6], 20, 'Because you cook at home'];
        }
        if ($food === 'raw') {
            $rules[] = [[5, 6], 14, 'Because you feed raw'];
        }
        if ($sub('activity') < 0.6) {
            $rules[] = [[19, 27], 16, 'For more activity and brain work'];
        }
        if ($sub('routine') < 0.6) {
            $rules[] = [[2, 19], 14, 'For better portions and routine'];
        }
        // Everyone benefits from the first lesson.
        $rules[] = [[1], 10, 'The best place to start'];
        return $rules;
    }
}
