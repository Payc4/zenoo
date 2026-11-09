<?php

namespace App\Chat;

use App\Command\ImportCourseCommand;
use App\Entity\Lesson;
use App\Entity\User;
use App\Entity\DogProfile;
use App\Repository\CourseRepository;
use App\Repository\DogProfileRepository;
use App\Repository\LessonProgressRepository;

/**
 * What the chat coach knows from the ZENOO Dog Wellness Course.
 *
 * knowledge(): the English lessons as compact text for the system prompt
 *   ({{course_knowledge}} in config/chatbot/system_prompt.md). Read from the database,
 *   so edits made in the Course studio reach the coach straight away.
 * progress(): one pet parent's finished lessons and written answers ({{course_progress}}).
 * dog(): their dog's profile from the Bowl Score quiz ({{dog_profile}}).
 */
class CourseKnowledge
{
    /** Screens the coach learns from (the hook, quizzes and badges add little). */
    private const LAYOUTS = ['read' => 'The science', 'dog' => 'Do it with your dog', 'deeper' => 'Go deeper', 'vet' => null];

    public function __construct(
        private readonly CourseRepository $courses,
        private readonly LessonProgressRepository $progress,
        private readonly DogProfileRepository $dogs,
        private readonly CheckIns $checkIns,
    ) {
    }

    public function knowledge(): string
    {
        $course = $this->courses->findOneBy(['slug' => ImportCourseCommand::COURSES['en']['slug']]);
        if (!$course || $course->getLessons()->isEmpty()) {
            return '(The course has not been loaded yet. Answer from general, well-established veterinary nutrition knowledge.)';
        }

        $out = [];
        $n = 0;
        foreach ($course->getLessons() as $lesson) {
            $out[] = $this->lesson(++$n, $lesson);
        }
        return implode("\n\n", $out);
    }

    public function progress(User $user): string
    {
        $data = $this->progress->summaryForCoach($user);
        if (!$data['completed'] && !$data['answers']) {
            return 'They have not started the course yet. If it fits, mention that the Courses tab has short lessons on their topic.';
        }

        $lines = [];
        if ($data['completed']) {
            $lines[] = 'Lessons they have finished: ' . implode('; ', $data['completed']) . '.';
        }
        if ($data['answers']) {
            $lines[] = 'What they wrote in the lessons (newest first):';
            foreach ($data['answers'] as $a) {
                $lines[] = sprintf('- %s — task: %s — their answer: "%s"', $a['lesson'], $this->oneLine($a['task']), $this->oneLine($a['answer']));
            }
        }
        return implode("\n", $lines);
    }

    private function lesson(int $n, Lesson $lesson): string
    {
        $parts = ["### Lesson $n: " . $lesson->getTitle()];
        foreach ($lesson->getSlides() as $slide) {
            $layout = $slide['layout'] ?? '';
            if (!\array_key_exists($layout, self::LAYOUTS)) {
                continue;
            }
            $f = $slide['fields'] ?? [];
            if ($layout === 'vet') {
                $parts[] = $this->compact((string) ($f['vet'] ?? ''));
                if (!empty($f['takeaways'])) {
                    $parts[] = 'Key takeaways: ' . implode(' ', $f['takeaways']);
                }
                continue;
            }
            $text = (string) ($f['body'] ?? '');
            if ($layout === 'dog') {
                $steps = array_map(fn ($s, $i) => ($i + 1) . '. ' . $s, $f['steps'] ?? [], array_keys($f['steps'] ?? []));
                $text = trim(($f['task'] ?? '') . "\n" . implode("\n", $steps) . "\n" . $text);
            }
            if (trim($text) === '') {
                continue;
            }
            $title = $layout === 'deeper' && !empty($f['headline']) ? 'Go deeper (' . $f['headline'] . ')' : self::LAYOUTS[$layout];
            $parts[] = $title . ': ' . $this->compact($text);
        }
        return implode("\n", $parts);
    }

    /** Fewer blank lines and no table padding: same content, fewer tokens. */
    private function compact(string $text): string
    {
        $text = preg_replace('/^\|[\s:|-]+\|$\n?/mu', '', $text);
        $text = preg_replace('/[ \t]*\|[ \t]*/u', ' | ', $text);
        $text = preg_replace('/\n\s*\n/u', "\n", trim($text));
        return $text;
    }

    private function oneLine(string $s): string
    {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', $s)), 0, 400);
    }

    public function dog(User $user): string
    {
        $dog = $this->dogs->findForUser($user);
        if (!$dog) {
            return 'They have not taken the Bowl Score quiz yet, so you don\'t know their dog\'s details unless they tell you. If it helps, suggest the free 3-minute quiz (Bowl Score quiz in the menu).';
        }
        $g = fn (string $k) => $dog->get($k);
        $lines = ['Their dog, from the Bowl Score quiz on ' . $dog->getUpdatedAt()->format('j F Y') . ':'];
        $facts = array_filter([
            'Name' => $dog->getName(),
            'Breed' => $g('breed') === 'mixed' ? 'mixed breed (' . $g('breed_size') . ')' : $g('breed'),
            'Age' => $g('age_years') !== null ? $g('age_years') . ' years (' . $g('life_stage') . ')' : null,
            'Sex' => $g('sex') ? $g('sex') . ($g('neutered') ? ', neutered' : ', not neutered') : null,
            'Weight' => $g('weight_kg') ? $g('weight_kg') . ' kg' . ($g('ideal_weight_kg') ? ' (ideal about ' . $g('ideal_weight_kg') . ' kg)' : '') : null,
            'Body condition score' => $g('bcs') ? $g('bcs') . '/9' : null,
            'Activity' => $g('activity_band') ? 'level ' . $g('activity_band') . ' (recommended ' . $g('recommended_activity_band') . ')' : null,
            'Main food' => $g('food_main'),
            'All foods' => \is_array($g('food_types')) ? implode(', ', $g('food_types')) : null,
            'Dry matter' => $g('protein_dm') !== null ? 'protein about ' . $g('protein_dm') . '%, carbs about ' . $g('carbs_dm') . '%' : null,
            'Appetite' => $g('appetite'),
            'Signs the pet parent noticed' => \is_array($g('signs')) && $g('signs') ? implode(', ', $g('signs')) : 'none',
            'Meals per day' => $g('meals_per_day'),
            'Treats' => $g('treats'),
            'Daily energy target' => $g('kcal_target') ? round((float) $g('kcal_target')) . ' kcal' : null,
        ], fn ($v) => $v !== null && $v !== '');
        foreach ($facts as $k => $v) {
            $lines[] = "- $k: $v";
        }
        $lines[] = '- Bowl Score: ' . $dog->getBowlScore() . '/100 (' . $dog->band() . '). Breakdown: '
            . implode(', ', array_map(fn ($s) => $s['key'] . ' ' . $s['score'] . '/' . $s['max'], $dog->subScores())) . '.';
        foreach (['fix_1_text' => 'First tip the quiz gave', 'fix_2_text' => 'Second tip', 'plan_food' => 'Daily food plan', 'plan_meals' => 'Meals'] as $k => $label) {
            if ($g($k)) {
                $lines[] = "- $label: " . $this->oneLine((string) $g($k));
            }
        }
        $lines[] = 'This is what they told the quiz; it is not a vet assessment. Their dog may have changed since.';
        $checkIns = $this->checkIns->forCoach($dog);
        if ($checkIns !== '') {
            $lines[] = $checkIns;
        }
        return implode("\n", $lines);
    }

    public function language(User $user): string
    {
        return $user->getLocale() === 'lt'
            ? 'Their site language is Lithuanian: reply in Lithuanian unless they write in another language.'
            : '';
    }
}
