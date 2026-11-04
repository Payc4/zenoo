<?php

namespace App\Course;

/**
 * The slide layouts a lesson can use, and the boxes ("fields") each one has.
 * The ZENOO lesson format uses them in the order of self::STANDARD.
 *
 * The admin slide editor builds its forms from this list (it is passed to the
 * page as JSON), and templates/course/slides/<layout>.html.twig renders each
 * layout for learners. To add a layout: add an entry here and a template there.
 *
 * Field types:
 *   text      one line
 *   textarea  several lines; supports **bold**, *italic*, "- " bullet lines and "|" tables
 *   list      one item per line
 *   choices   answer buttons, one marked correct
 *   audio     uploaded audio file (stored as its URL)
 *   number    whole number
 *   checkbox  yes/no
 *   group     a repeatable set of the sub-fields in "fields" (e.g. quiz questions)
 */
final class LessonLayouts
{
    public const TRANSITIONS = ['slide' => 'Slide', 'fade' => 'Fade', 'none' => 'None'];

    public static function all(): array
    {
        $headline = ['key' => 'headline', 'type' => 'text', 'label' => 'Headline'];
        $button = fn (string $default = 'Continue') => ['key' => 'button', 'type' => 'text', 'label' => 'Button text', 'default' => $default];
        $richHelp = 'Use **bold** and *italic*. Start a line with "- " for a bullet point.';
        $tableHelp = 'A table: one row per line, cells split by "|", the first row is the header.';

        return [
            'hook' => [
                'label' => 'The hook',
                'mode' => 'Read',
                'icon' => '📖',
                'description' => 'Opening scene with a short "In this lesson" list.',
                'fields' => [
                    $headline,
                    ['key' => 'body', 'type' => 'textarea', 'label' => 'Story', 'rows' => 10, 'help' => $richHelp],
                    ['key' => 'listTitle', 'type' => 'text', 'label' => 'List title', 'default' => 'In this lesson:'],
                    ['key' => 'list', 'type' => 'list', 'label' => 'List items', 'help' => 'One item per line.'],
                    $button(),
                ],
            ],
            'read' => [
                'label' => 'The science, simply',
                'mode' => 'Read',
                'icon' => '🔬',
                'description' => 'Plain-words explanation of what is going on inside the dog.',
                'fields' => [
                    ['key' => 'headline', 'type' => 'text', 'label' => 'Headline', 'default' => 'The science, simply'],
                    ['key' => 'body', 'type' => 'textarea', 'label' => 'Text', 'rows' => 12, 'help' => $richHelp . ' ' . $tableHelp],
                    $button(),
                ],
            ],
            'listen' => [
                'label' => 'Listen',
                'mode' => 'Listen',
                'icon' => '🎧',
                'description' => 'Audio from Agota with a transcript and one check question.',
                'fields' => [
                    $headline,
                    ['key' => 'audio', 'type' => 'audio', 'label' => 'Audio file', 'help' => 'MP3 or M4A, up to 10 MB.'],
                    ['key' => 'script', 'type' => 'textarea', 'label' => 'Transcript', 'rows' => 8, 'help' => 'Shown under the player as "Read along". Until the audio is uploaded, learners read it instead.'],
                    ['key' => 'question', 'type' => 'text', 'label' => 'Check question'],
                    ['key' => 'choices', 'type' => 'choices', 'label' => 'Answers', 'help' => 'Tick the correct answer.', 'default' => self::blankChoices()],
                    ['key' => 'feedback', 'type' => 'textarea', 'label' => 'If right', 'rows' => 3],
                    ['key' => 'incorrect', 'type' => 'textarea', 'label' => 'If wrong', 'rows' => 3, 'help' => 'Leave empty to show "Not quite." and the text above.'],
                    $button(),
                ],
            ],
            'quiz' => [
                'label' => 'Quick check',
                'mode' => 'Read',
                'icon' => '✅',
                'description' => 'Multiple-choice questions with right/wrong feedback.',
                'fields' => [
                    ['key' => 'headline', 'type' => 'text', 'label' => 'Headline', 'default' => 'Quick check'],
                    ['key' => 'questions', 'type' => 'group', 'label' => 'Questions', 'itemLabel' => 'Question', 'min' => 1, 'max' => 5,
                        'default' => [['choices' => self::blankChoices()], ['choices' => self::blankChoices()]],
                        'fields' => [
                            ['key' => 'question', 'type' => 'textarea', 'label' => 'Question', 'rows' => 2],
                            ['key' => 'choices', 'type' => 'choices', 'label' => 'Answers', 'help' => 'Tick the correct answer.'],
                            ['key' => 'correct', 'type' => 'textarea', 'label' => 'If right', 'rows' => 2, 'placeholder' => 'Exactly. …'],
                            ['key' => 'incorrect', 'type' => 'textarea', 'label' => 'If wrong', 'rows' => 2, 'placeholder' => 'Not quite. …'],
                        ]],
                    $button('Continue'),
                ],
            ],
            'write' => [
                'label' => 'Write',
                'mode' => 'Write',
                'icon' => '✍️',
                'description' => 'A short log or plan about the learner\'s own dog, with weak and model examples. Answers are saved to their account and the chat coach can see them.',
                'fields' => [
                    $headline,
                    ['key' => 'intro', 'type' => 'textarea', 'label' => 'Intro', 'rows' => 3, 'help' => $richHelp],
                    ['key' => 'prompts', 'type' => 'group', 'label' => 'Writing tasks', 'itemLabel' => 'Task', 'min' => 1, 'max' => 3,
                        'default' => [[]],
                        'fields' => [
                            ['key' => 'instructions', 'type' => 'textarea', 'label' => 'Instructions', 'rows' => 3, 'help' => $richHelp],
                            ['key' => 'weak', 'type' => 'textarea', 'label' => 'Weak example', 'rows' => 2],
                            ['key' => 'weakWhy', 'type' => 'textarea', 'label' => 'Why it\'s weak', 'rows' => 2],
                            ['key' => 'placeholder', 'type' => 'text', 'label' => 'Text box placeholder'],
                            ['key' => 'helper', 'type' => 'text', 'label' => 'Helper text'],
                            ['key' => 'model', 'type' => 'textarea', 'label' => 'Model answer', 'rows' => 3, 'help' => 'Shown after the learner submits.'],
                            ['key' => 'modelWhy', 'type' => 'textarea', 'label' => 'Why it works', 'rows' => 2],
                        ]],
                    $button('Continue'),
                ],
            ],
            'dog' => [
                'label' => 'Do it with your dog',
                'mode' => 'Do',
                'icon' => '🐾',
                'description' => 'A hands-on check or task with the dog, as numbered steps.',
                'fields' => [
                    ['key' => 'headline', 'type' => 'text', 'label' => 'Headline', 'default' => 'Do it with your dog'],
                    ['key' => 'task', 'type' => 'textarea', 'label' => 'Task', 'rows' => 3, 'help' => $richHelp],
                    ['key' => 'steps', 'type' => 'list', 'label' => 'Steps', 'help' => 'One per line. Shown as a numbered list.'],
                    ['key' => 'body', 'type' => 'textarea', 'label' => 'More (optional)', 'rows' => 6, 'help' => $richHelp . ' ' . $tableHelp],
                    ['key' => 'doneLabel', 'type' => 'text', 'label' => 'Tick box text', 'default' => 'Done it with my dog'],
                    $button(),
                ],
            ],
            'deeper' => [
                'label' => 'Go deeper',
                'mode' => 'Read',
                'icon' => '🔍',
                'description' => 'One advanced idea or ZENOO tip.',
                'fields' => [
                    $headline,
                    ['key' => 'body', 'type' => 'textarea', 'label' => 'Text', 'rows' => 10, 'help' => $richHelp . ' ' . $tableHelp],
                    $button(),
                ],
            ],
            'vet' => [
                'label' => 'Vet check + today\'s practice',
                'mode' => 'Do',
                'icon' => '🩺',
                'description' => 'Red-flag signs that need a vet, one action for the coming days, and key takeaways.',
                'fields' => [
                    ['key' => 'headline', 'type' => 'text', 'label' => 'Headline', 'default' => 'Vet check'],
                    ['key' => 'vet', 'type' => 'textarea', 'label' => 'When to call the vet', 'rows' => 8, 'help' => $richHelp . ' Start with a bold title such as **Call your vet if you see:**'],
                    ['key' => 'actionTitle', 'type' => 'text', 'label' => 'Practice title', 'default' => 'For the next 24 hours:'],
                    ['key' => 'action', 'type' => 'textarea', 'label' => 'Practice', 'rows' => 3, 'help' => $richHelp],
                    ['key' => 'takeawaysTitle', 'type' => 'text', 'label' => 'Takeaways title', 'default' => 'Key takeaways:'],
                    ['key' => 'takeaways', 'type' => 'list', 'label' => 'Takeaways', 'help' => 'One per line.'],
                    $button(),
                ],
            ],
            'achievement' => [
                'label' => 'Achievement',
                'mode' => 'Finish',
                'icon' => '🏅',
                'description' => 'Badge and celebration. Lesson number and streak are added automatically.',
                'fields' => [
                    ['key' => 'emoji', 'type' => 'text', 'label' => 'Badge icon (emoji)', 'default' => '🏅'],
                    ['key' => 'badge', 'type' => 'text', 'label' => 'Badge name'],
                    ['key' => 'extra', 'type' => 'text', 'label' => 'Extra progress line (optional)', 'help' => 'e.g. "Day 1 of your 7-day poop log". Lesson number and streak are added for you.'],
                    ['key' => 'celebration', 'type' => 'textarea', 'label' => 'Celebration text', 'rows' => 4, 'help' => $richHelp],
                    $button('Finish lesson'),
                ],
            ],
        ];
    }

    /** The 9-screen ZENOO lesson format, in order. */
    public const STANDARD = ['hook', 'read', 'listen', 'quiz', 'write', 'dog', 'deeper', 'vet', 'achievement'];

    /**
     * Words the player shows around the lesson content, per course language.
     * Only the boxes the author types are translated by the author; these are the rest.
     */
    public static function ui(string $locale): array
    {
        $en = [
            'read' => 'Read', 'listen' => 'Listen', 'write' => 'Write', 'do' => 'Do it with your dog', 'deeper' => 'Go deeper',
            'quiz' => 'Quick check', 'vet' => 'Vet check', 'continue' => 'Continue', 'finish' => 'Finish lesson',
            'readAlong' => 'Read along', 'audioSoon' => 'Agota\'s recording is coming soon. Read the script below for now.',
            'correct' => 'Correct!', 'incorrect' => 'Not quite.', 'weak' => 'Weak example', 'model' => 'Model answer',
            'check' => 'Check my answer', 'placeholder' => 'Write your answer…', 'part' => 'Part',
            'badge' => 'Badge earned', 'lessonOf' => 'Lesson %n% of %total% complete', 'streak' => 'Day %n% streak',
            'close' => 'Close lesson', 'back' => 'Previous screen', 'done' => 'Done it with my dog', 'savedNote' => 'Saved to your account. Your ZENOO coach in Chat can see it too.',
            'needAnswer' => 'Answer the question first.', 'needTask' => 'Write your answer and tap “Check my answer” first.',
            'needWords' => 'Write a few words first.', 'previewEnd' => 'Preview: the lesson ends here.',
        ];
        $lt = [
            'read' => 'Skaityk', 'listen' => 'Klausyk', 'write' => 'Rašyk', 'do' => 'Daryk su savo šunimi', 'deeper' => 'Giliau',
            'quiz' => 'Greita patikra', 'vet' => 'Veterinaro patikra', 'continue' => 'Toliau', 'finish' => 'Baigti pamoką',
            'readAlong' => 'Skaitykite kartu', 'audioSoon' => 'Agotos įrašas netrukus. Kol kas perskaitykite tekstą žemiau.',
            'correct' => 'Teisingai!', 'incorrect' => 'Ne visai.', 'weak' => 'Silpnas pavyzdys', 'model' => 'Pavyzdinis atsakymas',
            'check' => 'Tikrinti atsakymą', 'placeholder' => 'Parašykite atsakymą…', 'part' => 'Dalis',
            'badge' => 'Gautas ženklelis', 'lessonOf' => '%n% pamoka iš %total% baigta', 'streak' => 'Serija: %n% d.',
            'close' => 'Uždaryti pamoką', 'back' => 'Ankstesnis ekranas', 'done' => 'Padariau su savo šunimi', 'savedNote' => 'Išsaugota jūsų paskyroje. ZENOO patarėjas pokalbyje irgi tai mato.',
            'needAnswer' => 'Pirmiausia atsakykite į klausimą.', 'needTask' => 'Parašykite atsakymą ir paspauskite „Tikrinti atsakymą“.',
            'needWords' => 'Parašykite bent kelis žodžius.', 'previewEnd' => 'Peržiūra: pamoka čia baigiasi.',
        ];
        return $locale === 'lt' ? $lt : $en;
    }

    /** Four empty answer buttons, the first marked correct, for new questions. */
    public static function blankChoices(): array
    {
        return [['text' => '', 'correct' => true], ['text' => '', 'correct' => false], ['text' => '', 'correct' => false], ['text' => '', 'correct' => false]];
    }

    public static function has(string $layout): bool
    {
        return isset(self::all()[$layout]);
    }

    /**
     * Cleans slides coming from the editor: drops unknown layouts and keys,
     * casts types, caps lengths. Never trusts the browser.
     */
    public static function sanitizeSlides(array $slides): array
    {
        $out = [];
        $seen = [];
        foreach (array_slice($slides, 0, 60) as $slide) {
            if (!\is_array($slide) || !self::has((string) ($slide['layout'] ?? ''))) {
                continue;
            }
            $layout = $slide['layout'];
            $id = preg_match('/^s_[a-z0-9]{4,16}$/', (string) ($slide['id'] ?? '')) ? $slide['id'] : 's_' . bin2hex(random_bytes(4));
            if (isset($seen[$id])) {
                $id = 's_' . bin2hex(random_bytes(4));
            }
            $seen[$id] = true;
            $transition = isset(self::TRANSITIONS[$slide['transition'] ?? '']) ? $slide['transition'] : 'slide';
            $out[] = [
                'id' => $id,
                'layout' => $layout,
                'transition' => $transition,
                'fields' => self::sanitizeFields(self::all()[$layout]['fields'], \is_array($slide['fields'] ?? null) ? $slide['fields'] : []),
            ];
        }
        return $out;
    }

    private static function sanitizeFields(array $defs, array $values): array
    {
        $clean = [];
        foreach ($defs as $def) {
            $v = $values[$def['key']] ?? null;
            $clean[$def['key']] = match ($def['type']) {
                'text', 'audio' => mb_substr(trim((string) ($v ?? '')), 0, 500),
                'textarea' => mb_substr((string) ($v ?? ''), 0, 8000),
                'list' => array_values(array_slice(array_filter(array_map(fn ($x) => mb_substr(trim((string) $x), 0, 600), \is_array($v) ? $v : []), 'strlen'), 0, 20)),
                'number' => max(0, min(20, (int) ($v ?? 0))),
                'checkbox' => (bool) $v,
                'choices' => array_values(array_map(fn ($c) => [
                    'text' => mb_substr(trim((string) ($c['text'] ?? '')), 0, 500),
                    'correct' => (bool) ($c['correct'] ?? false),
                ], array_slice(\is_array($v) ? array_filter($v, 'is_array') : [], 0, 6))),
                'group' => array_values(array_map(
                    fn ($item) => self::sanitizeFields($def['fields'], \is_array($item) ? $item : []),
                    array_slice(\is_array($v) ? $v : [], 0, $def['max'] ?? 10)
                )),
                default => null,
            };
        }
        return $clean;
    }
}
