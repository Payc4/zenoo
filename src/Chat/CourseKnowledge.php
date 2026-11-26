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
        private readonly \App\Course\LessonAccess $access,
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
        $locked = $this->lockedFor($user);
        if (!$data['completed'] && !$data['answers']) {
            return 'They have not started the course yet. If it fits, mention that the Courses tab has short lessons on their topic.' . $locked;
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
        return implode("\n", $lines) . $locked;
    }

    /** Lessons this person can't open yet (locked in the Course studio, no lesson unlock bought). */
    private function lockedFor(User $user): string
    {
        $course = $this->courses->findOneBy(['slug' => ImportCourseCommand::COURSES['en']['slug']]);
        if (!$course || $this->access->hasUnlock($user)) {
            return '';
        }
        $numbers = [];
        foreach (array_values($course->getLessons()->toArray()) as $i => $lesson) {
            if ($lesson->isLocked()) {
                $numbers[] = $i + 1;
            }
        }
        if (!$numbers) {
            return '';
        }
        return "\nLocked for them: lesson" . (\count($numbers) > 1 ? 's ' : ' ') . implode(', ', $numbers)
            . '. They open after a one-off payment ("Unlock all lessons" on the Courses page). You can still use what those lessons teach;'
            . ' if you link one, say it is part of the unlock. Don\'t push the purchase.';
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

    /** The pet this chat is about ($dog), else the pet parent's selected pet. */
    public function dog(User $user, ?DogProfile $dog = null): string
    {
        $dog ??= $this->dogs->findForUser($user);
        if (!$dog) {
            return 'They have not taken the Bowl Score quiz yet, so you don\'t know their dog\'s details unless they tell you. If it helps, suggest the free 3-minute quiz (Bowl Score quiz in the menu).';
        }
        $g = fn (string $k) => $dog->get($k);
        $lines = ['Their dog, from the Bowl Score quiz on ' . $dog->getUpdatedAt()->format('j F Y') . ':'];
        $facts = array_filter([
            'Name' => $dog->getName(),
            'Breed' => $g('breed') === 'mixed' ? 'mixed breed (' . $g('breed_size') . ')' : $g('breed'),
            'Age' => $g('age_years') !== null ? ((float) $g('age_years') < 1 ? (int) round((float) $g('age_years') * 52) . ' weeks' : $g('age_years') . ' years') . ' (' . $g('life_stage') . ')' : null,
            'Sex' => $g('sex') ? $g('sex') . ($g('neutered') ? ', neutered' : ', not neutered') : null,
            'Weight' => $g('weight_kg') ? $g('weight_kg') . ' kg' . ($g('ideal_weight_kg') ? ' (ideal about ' . $g('ideal_weight_kg') . ' kg)' : '') : null,
            'Growth' => $g('life_stage') === 'puppy' ? 'still growing: no target weight; weigh weekly and expect steady gain' . ($g('adult_weight_kg') ? ', to about ' . $g('adult_weight_kg') . ' kg as an adult' : '') : null,
            'Body condition score' => $g('bcs') ? $g('bcs') . '/9' : null,
            'Activity' => $g('activity_band') ? 'level ' . $g('activity_band') . ' (recommended ' . $g('recommended_activity_band') . ')' : null,
            'Main food' => $g('food_main'),
            'All foods' => \is_array($g('food_types')) ? implode(', ', $g('food_types')) : null,
            'Dry matter' => $g('protein_dm') !== null ? 'protein about ' . $g('protein_dm') . '%, carbs about ' . $g('carbs_dm') . '%' : null,
            'Appetite' => $g('appetite'),
            'Signs the pet parent noticed' => \is_array($g('signs')) && $g('signs') ? implode(', ', $g('signs')) : 'none',
            'Meals per day' => $g('meals_per_day'),
            'Treats' => $g('treats'),
            'Daily energy target' => $g('kcal_target') ? round((float) $g('kcal_target')) . ' kcal' . match ($g('plan_keep')) {
                'heavy' => ' (held at today\'s intake: above ideal shape, so never advise more)',
                'thin' => ' (held at today\'s intake: below ideal shape, so never advise less)',
                'vet' => ' (today\'s intake; the formula was over 20% away, so the amounts are for the vet to check)',
                default => '',
            } : null,
            'Eats now' => $g('intake_kcal') ? round((float) $g('intake_kcal')) . ' kcal a day (food and treats, at the quiz)' : null,
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
        $food = $dog->getFood();
        $lines[] = $food
            ? '- Food they feed (saved ' . $food['at'] . ', from the ' . $food['source'] . '): ' . trim($food['brand'] . ' ' . $food['product'])
                . ($food['format'] ? ' (' . $food['format'] . ')' : '') . ($food['notes'] ? '. Label notes: ' . $this->oneLine($food['notes']) : '')
            : '- Food brand: not known yet. When it fits, early in the chat, invite them once to snap a photo of the food label with the camera button next to the message box, or to type the food\'s name, and offer to check it. Then save it with save_food.';
        if ($w = $dog->getWeightUpdate()) {
            $lines[] = '- Weight they told you later (' . $w['at'] . '): ' . $w['kg'] . ' kg. Use this instead of the quiz weight.';
        }
        $notes = $dog->getNotes();
        // Allergies, conditions (past ones too) and medicines are rules, not background (F6).
        if ($rules = \App\Chat\ReplyGuard::rules($dog)) {
            $lines[] = "- RULES FOR THIS DOG (from their notes; follow them in every answer, even if the chat was cleared):\n"
                . implode("\n", array_map(fn ($r) => '  - ' . $r, $rules))
                . "\n  Never suggest or approve a food, treat or ingredient the dog is allergic or intolerant to. After pancreatitis (now or in the past), no high-fat food or fatty treats (pork, fatty meat, skin, cheese). Check anything that could clash with a medicine with the vet.";
        }
        $lines[] = $notes
            ? "- What they have told you in the chat (from your update_dog_profile notes, oldest first):\n" . implode("\n", array_map(fn ($n) => '  - ' . $n['at'] . ' [' . $n['topic'] . (($n['source'] ?? 'chat') === 'owner' ? ', added by the pet parent' : '') . '] ' . $n['text'], $notes))
            : '- Nothing saved from the chat yet. When they tell you something lasting about the dog, save it with update_dog_profile.';
        $names = ['weight' => 'weight (kg)', 'body' => 'body condition (1–9, 5 ideal)', 'stool' => 'stool score (1–7, 2 ideal)', 'itch' => 'itching (0–10)'];
        $tracked = [];
        foreach ($names as $type => $label) {
            $log = \array_slice($dog->getLog($type), -4);
            if ($log && !(\count($log) === 1 && ($log[0]['src'] ?? '') === 'quiz')) {
                $tracked[] = '  - ' . $label . ': ' . implode(', ', array_map(fn ($e) => $e['d'] . ' ' . $e['v'], $log));
            }
        }
        if ($tracked) {
            $lines[] = "- Their tracker diary (oldest to newest; use the newest values):\n" . implode("\n", $tracked);
        }
        $history = $dog->getScoreHistory();
        if (\count($history) > 1) {
            $lines[] = '- Bowl Score over time: ' . implode(' → ', array_map(fn ($h) => $h['score'] . ' (' . $h['at'] . ')', $history)) . '.';
        }
        $open = array_filter($dog->getFollowUps(), fn ($f) => !$f['done']);
        if ($open) {
            $lines[] = '- Check-ins you planned: ' . implode('; ', array_map(fn ($f) => $f['due'] . ' (' . $f['topic'] . '): "' . $f['question'] . '"', $open)) . '.';
        }
        $lines[] = $dog->getPhoto()
            ? '- They added a photo of ' . $dog->getName() . ' to the profile.'
            : '- No photo of ' . $dog->getName() . ' yet; they can add one on the dashboard ("Finish the profile").';
        $lines[] = 'This is what they told the quiz; it is not a vet assessment. Their dog may have changed since.';
        $checkIns = $this->checkIns->forCoach($dog);
        if ($checkIns !== '') {
            $lines[] = $checkIns;
        }
        // Other dogs in the household (roadmap 07): the chat is about the selected one.
        $others = array_values(array_filter($this->dogs->findAllForUser($user), fn ($d) => $d->getId() !== $dog->getId()));
        if ($others) {
            $lines[] = '- Their other dog' . (\count($others) > 1 ? 's' : '') . ' (each pet has its own chat; they switch pets in the chat list on the left, or add one with New chat): '
                . implode('; ', array_map(fn ($d) => $d->getName() . ' (' . ($d->get('breed') ?: 'breed not given') . ', ' . ($d->get('age_years') !== null ? $d->get('age_years') . ' years' : 'age not given') . ', Bowl Score ' . $d->getBowlScore() . ')', $others))
                . '. Everything above is about ' . $dog->getName() . '; if they ask about another dog, say this chat is about ' . $dog->getName() . ' and suggest opening that dog\'s chat at the top.';
        }
        return implode("\n", $lines);
    }

    /**
     * The language to answer in: the interface they're using right now ($locale, from the page),
     * else their account's language (emails, scheduled check-ins). Test report N6: the coach greeted
     * in Lithuanian inside the English interface.
     */
    public function language(User $user, ?string $locale = null): string
    {
        $locale = \App\Locale::isSupported($locale) ? $locale : $user->getLocale();
        $language = \App\Locale::ENGLISH_NAMES[$locale] ?? null;
        if ($language === null) {
            return '';
        }
        // The app's own names in that language, so a reply never mixes in the English ones
        // ("Bowl Score" in a Lithuanian answer while the screen says "Dubenėlio balas").
        $terms = [
            'lt' => ['Dubenėlio balas', 'patarėjas'],
            'uk' => ['Оцінка миски', 'помічник'],
            'pl' => ['Wynik miski', 'asystent'],
        ][$locale] ?? null;
        $glossary = $terms
            ? sprintf(' Use the app\'s %s names: the Bowl Score is "%s" (never "Bowl Score"), and you, the coach, are "%s".', $language, $terms[0], $terms[1])
            : '';

        return "Their site language is $language: reply in $language unless they write in another language." . $glossary;
    }
}
