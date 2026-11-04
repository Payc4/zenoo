<?php

namespace App\Course;

/**
 * Reads the ZENOO course document (content/courses/zenoo-dog-wellness.md, the Word file
 * converted with `pandoc -t gfm --wrap=none`) and turns each lesson's nine screens into
 * lesson slides (layouts in LessonLayouts::STANDARD).
 *
 * The document has the English lessons first ("## Lesson N — Title", "### Screen k · …")
 * and then the Lithuanian ones ("## Lietuviška versija", "### N pamoka — …", "#### k ekranas · …").
 *
 * Notes for the team are not published: "[Agota: …]" / "[Vet/Agota: …]" reminders and "App note:" lines are
 * removed from the text and returned in `notes`, so someone can follow them up.
 */
final class CourseDocumentParser
{
    private const LABELS = [
        'en' => [
            'headline' => 'Headline', 'inLesson' => 'In this lesson', 'question' => 'Check question', 'feedback' => 'Feedback',
            'intro' => 'Practice intro', 'instructions' => 'Instructions', 'weak' => 'Weak example', 'weakWhy' => "Why it's weak",
            'placeholder' => 'Placeholder', 'model' => 'Model answer', 'modelWhy' => 'Why it works', 'task' => 'Task',
            'takeaways' => 'Key takeaways', 'badge' => 'Badge', 'progress' => 'Progress', 'celebration' => 'Celebration text',
            'correctMark' => '(correct)', 'right' => 'Correct', 'wrong' => 'Incorrect', 'audio' => 'Audio script',
            'appNote' => 'App note', 'quiz' => 'Quick check', 'vet' => 'Vet check', 'do' => 'Do it with your dog',
            'qPattern' => '/^\*\*Q(\d+)\.\*\*\s*(.*)$/u',
            'standardProgress' => '/^(Lesson \d+ of \d+ complete|Day \d+ streak)$/u',
        ],
        'lt' => [
            'headline' => 'Antraštė', 'inLesson' => 'Šioje pamokoje', 'question' => 'Klausimas', 'feedback' => 'Atsakymas',
            'intro' => 'Įvadas', 'instructions' => 'Užduotis', 'weak' => 'Silpnas pavyzdys', 'weakWhy' => 'Kodėl silpnas',
            'placeholder' => 'Lauko tekstas', 'model' => 'Pavyzdinis atsakymas', 'modelWhy' => 'Kodėl tinka', 'task' => 'Užduotis',
            'takeaways' => 'Svarbiausia', 'badge' => 'Ženklelis', 'progress' => 'Progresas', 'celebration' => 'Sveikinimas',
            'correctMark' => '(teisinga)', 'right' => 'Teisingai', 'wrong' => 'Neteisingai', 'audio' => 'Garso tekstas',
            'appNote' => 'Pastaba programėlei', 'quiz' => 'Greita patikra', 'vet' => 'Veterinaro patikra', 'do' => 'Daryk su savo šunimi',
            'qPattern' => '/^\*\*(\d+) klausimas\.\*\*\s*(.*)$/u',
            'standardProgress' => '/^(\d+ pamoka iš \d+ baigta|\d+ dien(os|ų) serija)$/u',
        ],
    ];

    /** One badge icon per lesson (the document names the badges but has no icons). */
    private const BADGE_EMOJI = [
        1 => '🔍', 2 => '🏷️', 3 => '🦠', 4 => '🧘', 5 => '🥣', 6 => '📋', 7 => '🔄', 8 => '🐾', 9 => '✨', 10 => '🍄',
        11 => '🍲', 12 => '🫐', 13 => '🦴', 14 => '🧱', 15 => '⚖️', 16 => '💪', 17 => '👀', 18 => '🧠', 19 => '🧩', 20 => '🐶',
        21 => '🌅', 22 => '💛', 23 => '🩹', 24 => '💡', 25 => '🏡', 26 => '🌿', 27 => '🐕', 28 => '🐟', 29 => '🛠️', 30 => '🎓',
    ];

    /** @var list<string> */
    private array $notes = [];

    /**
     * @return array{en: list<array>, lt: list<array>, notes: list<string>}
     *   Each lesson: {number, title, subtitle, minutes, vetReview, slides}
     */
    public function parse(string $markdown): array
    {
        $this->notes = [];
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
        [$en, $lt] = array_pad(preg_split('/^## Lietuviška versija\s*$/mu', $markdown, 2), 2, '');
        $subtitles = $this->courseMap($en);

        return [
            'en' => $this->lessons($en, 'en', '/^## Lesson (\d+) — (.+)$/mu', '/^### Screen (\d) · (.+)$/mu', $subtitles),
            'lt' => $this->lessons($lt, 'lt', '/^### (\d+) pamoka — (.+)$/mu', '/^#### (\d) ekranas · (.+)$/mu', []),
            'notes' => $this->notes,
        ];
    }

    /** "What the owner learns" column of the English course map, by lesson number. */
    private function courseMap(string $text): array
    {
        $out = [];
        if (preg_match('/^## Course map\s*$(.*?)^## /msu', $text, $m)) {
            foreach (explode("\n", $m[1]) as $line) {
                $cells = array_map('trim', explode('|', trim($line, " |")));
                if (\count($cells) >= 3 && ctype_digit($cells[0])) {
                    $out[(int) $cells[0]] = $this->clean($cells[2]);
                }
            }
        }
        return $out;
    }

    private function lessons(string $text, string $lang, string $lessonRe, string $screenRe, array $subtitles): array
    {
        $parts = preg_split($lessonRe, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $lessons = [];
        for ($i = 1; $i + 2 < \count($parts) + 1; $i += 3) {
            $number = (int) $parts[$i];
            $title = $this->clean($parts[$i + 1]);
            // A lesson ends at the next "## " heading (e.g. "## Sources").
            $body = preg_split('/^## /mu', $parts[$i + 2] ?? '')[0];
            $screens = preg_split($screenRe, $body, -1, PREG_SPLIT_DELIM_CAPTURE);
            $intro = $screens[0];
            $byNumber = [];
            for ($j = 1; $j + 2 < \count($screens) + 1; $j += 3) {
                $heading = $screens[$j + 1];
                $byNumber[(int) $screens[$j]] = [
                    'title' => str_contains($heading, '—') ? trim(explode('—', $heading, 2)[1]) : trim($heading),
                    'body' => $screens[$j + 2] ?? '',
                ];
            }
            if (\count($byNumber) !== 9) {
                throw new \RuntimeException(sprintf('%s lesson %d has %d screens, expected 9.', strtoupper($lang), $number, \count($byNumber)));
            }
            $where = sprintf('%s lesson %d', strtoupper($lang), $number);
            preg_match('/(\d+)\s*min/u', $intro, $mm);
            preg_match('/(?:Vet review|Veterinaro peržiūra):\s*([^.\n]+)/u', $intro, $vm);

            $lessons[] = [
                'number' => $number,
                'title' => $title,
                'subtitle' => $subtitles[$number] ?? null,
                'minutes' => isset($mm[1]) ? (int) $mm[1] : null,
                'vetReview' => isset($vm[1]) ? trim($vm[1]) : null,
                'slides' => $this->slides($byNumber, $lang, $number, $where),
            ];
        }
        return $lessons;
    }

    private function slides(array $s, string $lang, int $number, string $where): array
    {
        $L = self::LABELS[$lang];
        $ui = LessonLayouts::ui($lang);
        $slides = [];
        $add = function (string $layout, array $fields) use (&$slides, $lang, $number) {
            $i = \count($slides) + 1;
            $slides[] = [
                'id' => 's_' . substr(md5("zenoo-dog-wellness|$lang|$number|$i"), 0, 8),
                'layout' => $layout,
                'transition' => 'slide',
                'fields' => $fields + ['button' => ''],
            ];
        };

        // 1 · The hook
        $blocks = $this->blocks($s[1]['body'], "$where screen 1");
        $headline = '';
        $body = [];
        $list = [];
        $listTitle = $L['inLesson'] . ':';
        $inList = false;
        foreach ($blocks as $b) {
            if ($label = $this->label($b)) {
                if ($label[0] === $L['headline']) { $headline = $label[1]; continue; }
                if ($label[0] === $L['inLesson']) { $listTitle = $label[0] . ':'; $inList = true; continue; }
            }
            if ($inList && $this->isList($b)) { $list = array_merge($list, $this->listItems($b)); continue; }
            $body[] = $b;
        }
        $add('hook', ['headline' => $headline, 'body' => $this->join($body), 'listTitle' => $listTitle, 'list' => $list]);

        // 2 · The science, simply
        $add('read', ['headline' => $s[2]['title'], 'body' => $this->join($this->blocks($s[2]['body'], "$where screen 2"))]);

        // 3 · Listen
        $script = [];
        $question = '';
        $choices = [];
        $feedback = '';
        foreach ($this->blocks($s[3]['body'], "$where screen 3") as $b) {
            if (str_starts_with($b, '>')) {
                $script[] = trim(preg_replace('/^> ?/mu', '', $b));
            } elseif ($label = $this->label($b)) {
                if (str_starts_with($label[0], $L['audio'])) { continue; }
                if ($label[0] === $L['question']) { $question = $label[1]; }
                if ($label[0] === $L['feedback']) { $feedback = $label[1]; }
            } elseif ($this->isList($b)) {
                $choices = $this->choices($b, $L['correctMark']);
            }
        }
        // The document has one feedback line ("Right. …" / "Exactly. …"); wrong answers get "Not quite. …" instead.
        $because = preg_replace('/^(Right|Exactly|Correct|Teisingai|Būtent)\.\s*/u', '', $feedback);
        $add('listen', ['headline' => $s[3]['title'], 'audio' => '', 'script' => $this->join($script), 'question' => $question, 'choices' => $choices,
            'feedback' => $feedback, 'incorrect' => $because !== $feedback ? $ui['incorrect'] . ' ' . $because : '']);

        // 4 · Quick check
        $questions = [];
        foreach ($this->blocks($s[4]['body'], "$where screen 4") as $b) {
            if (preg_match($L['qPattern'], $b, $m)) {
                $questions[] = ['question' => trim($m[2]), 'choices' => [], 'correct' => '', 'incorrect' => ''];
            } elseif ($questions && $this->isList($b)) {
                $questions[\count($questions) - 1]['choices'] = $this->choices($b, $L['correctMark']);
            } elseif ($questions && preg_match('/^\*' . preg_quote($L['right'], '/') . ':\*\s*(.*?)\s*\*' . preg_quote($L['wrong'], '/') . ':\*\s*(.*)$/su', $b, $m)) {
                $questions[\count($questions) - 1]['correct'] = trim($m[1]);
                $questions[\count($questions) - 1]['incorrect'] = trim($m[2]);
            }
        }
        $add('quiz', ['headline' => $L['quiz'], 'questions' => $questions]);

        // 5 · Write
        $f = $this->labelled($this->blocks($s[5]['body'], "$where screen 5"));
        $add('write', [
            'headline' => $s[5]['title'],
            'intro' => $f[$L['intro']] ?? '',
            'prompts' => [[
                'instructions' => $f[$L['instructions']] ?? '',
                'weak' => $this->unquote($f[$L['weak']] ?? ''),
                'weakWhy' => $f[$L['weakWhy']] ?? '',
                'placeholder' => $f[$L['placeholder']] ?? '',
                'helper' => '',
                'model' => $this->unquote($f[$L['model']] ?? ''),
                'modelWhy' => $f[$L['modelWhy']] ?? '',
            ]],
        ]);

        // 6 · Do it with your dog: first paragraph = the task, a numbered list right after = steps, the rest = more.
        $blocks = $this->blocks($s[6]['body'], "$where screen 6");
        $task = '';
        $steps = [];
        $rest = [];
        foreach ($blocks as $i => $b) {
            if ($i === 0) {
                $label = $this->label($b);
                $task = $label && $label[0] === $L['task'] ? $label[1] : $b;
                if ($this->isList($b) && !$this->isNumbered($b)) { $task = ''; $rest[] = $b; }
            } elseif ($i === 1 && $this->isNumbered($b)) {
                $steps = $this->listItems($b);
            } else {
                $rest[] = $b;
            }
        }
        $add('dog', ['headline' => $L['do'], 'task' => $task, 'steps' => $steps, 'body' => $this->join($rest), 'doneLabel' => $ui['done']]);

        // 7 · Go deeper
        $add('deeper', ['headline' => $s[7]['title'], 'body' => $this->join($this->blocks($s[7]['body'], "$where screen 7"))]);

        // 8 · Vet check + today's practice: red flags, then the practice paragraph, then the takeaways.
        $blocks = $this->blocks($s[8]['body'], "$where screen 8");
        $k = null;
        foreach ($blocks as $i => $b) {
            $label = $this->label($b);
            if ($label && rtrim($label[0], ':') === $L['takeaways']) { $k = $i; break; }
        }
        if ($k === null || $k < 2) {
            throw new \RuntimeException("$where screen 8: no key takeaways found.");
        }
        $action = $this->label($blocks[$k - 1]) ?? ['', $blocks[$k - 1]];
        $takeaways = [];
        foreach (\array_slice($blocks, $k + 1) as $b) {
            $takeaways = array_merge($takeaways, $this->listItems($b));
        }
        $add('vet', [
            'headline' => $L['vet'],
            'vet' => $this->join(\array_slice($blocks, 0, $k - 1)),
            'actionTitle' => $action[0] !== '' ? $action[0] . ':' : '',
            'action' => $this->ucfirst($action[1]),
            'takeawaysTitle' => $L['takeaways'] . ':',
            'takeaways' => $takeaways,
        ]);

        // 9 · Achievement
        $f = $this->labelled($this->blocks($s[9]['body'], "$where screen 9"));
        $extra = array_filter(array_map('trim', explode('·', $f[$L['progress']] ?? '')), fn ($p) => $p !== '' && !preg_match($L['standardProgress'], $p));
        $add('achievement', [
            'emoji' => self::BADGE_EMOJI[$number] ?? '🏅',
            'badge' => $f[$L['badge']] ?? '',
            'extra' => implode(' · ', $extra),
            'celebration' => $f[$L['celebration']] ?? '',
            'button' => '',
        ]);

        return LessonLayouts::sanitizeSlides($slides);
    }

    // ───────────────────────── text helpers ─────────────────────────

    /**
     * Splits a screen into blocks (paragraphs, lists, tables, quotes), cleaned up for the
     * lesson_text filter: one list item per line, "1. " numbering, team notes removed.
     *
     * @return list<string>
     */
    private function blocks(string $text, string $where): array
    {
        $text = $this->clean($text, $where);
        $raw = preg_split('/\n\s*\n/u', trim($text));
        $out = [];
        foreach ($raw as $b) {
            $b = trim($b);
            if ($b === '') {
                continue;
            }
            if (preg_match('/^\*\*(App note|Pastaba programėlei):\*\*/u', $b)) {
                $this->notes[] = "$where: " . strip_tags(preg_replace('/\*\*/', '', $b));
                continue;
            }
            // Lists: "-   item" / "1.  item" -> "- item" / "1. item"
            $b = preg_replace('/^(\s*)-\s+/mu', '$1- ', $b);
            $b = preg_replace('/^(\s*)\d+\.\s+/mu', '$1§NUM§ ', $b);
            $last = $out ? $out[\count($out) - 1] : null;
            $isItem = (bool) preg_match('/^(- |§NUM§ )/u', $b);
            if ($isItem && $last !== null && preg_match('/^(- |§NUM§ )/u', $last)
                && (str_starts_with($b, '- ') === str_starts_with($last, '- '))) {
                $out[\count($out) - 1] = $last . "\n" . $b;   // same list continues
            } else {
                $out[] = $b;
            }
        }
        // Number each list from 1 (pandoc keeps counting across lists).
        return array_map(function (string $b) {
            $n = 0;
            return preg_replace_callback('/^§NUM§ /mu', function () use (&$n) { return (++$n) . '. '; }, $b);
        }, $out);
    }

    /** Removes pandoc escapes and the team's "*[Agota: …]*" / "*[Vet/Agota: …]*" notes (logged in $this->notes). */
    private function clean(string $text, string $where = ''): string
    {
        $text = preg_replace_callback('/\s?\*\\\\\[([^\]]*?)\\\\\]\*/u', function ($m) use ($where) {
            $this->notes[] = ($where !== '' ? "$where: " : '') . '[' . stripslashes($m[1]) . ']';
            return '';
        }, $text);
        return str_replace(['\\[', '\\]', '\\#'], ['[', ']', '#'], trim($text));
    }

    /** "**Label:** text" -> [Label, text]; null when the block doesn't start with a label. */
    private function label(string $block): ?array
    {
        if (preg_match('/^\*\*([^*\n]{1,80}?):\*\*\s*(.*)$/su', $block, $m)) {
            return [trim($m[1]), trim($m[2])];
        }
        return null;
    }

    /** Label => text, where later unlabelled blocks belong to the label before them. */
    private function labelled(array $blocks): array
    {
        $out = [];
        $current = null;
        foreach ($blocks as $b) {
            if ($label = $this->label($b)) {
                $current = $label[0];
                $out[$current] = $label[1];
            } elseif ($current !== null) {
                $out[$current] .= "\n\n" . $b;
            }
        }
        return $out;
    }

    private function isList(string $b): bool
    {
        return (bool) preg_match('/^(- |\d+\. )/u', $b);
    }

    private function isNumbered(string $b): bool
    {
        return (bool) preg_match('/^\d+\. /u', $b);
    }

    /** @return list<string> */
    private function listItems(string $b): array
    {
        $items = [];
        foreach (explode("\n", $b) as $line) {
            if (preg_match('/^\s*(?:- |\d+\. )(.*)$/u', $line, $m)) {
                $items[] = trim($m[1]);
            } elseif ($items && trim($line) !== '') {
                $items[\count($items) - 1] .= ' ' . trim($line);
            }
        }
        return $items;
    }

    /** Answer list where the right one is "**text (correct)**". */
    private function choices(string $b, string $mark): array
    {
        $out = [];
        foreach ($this->listItems($b) as $item) {
            $correct = str_contains($item, $mark);
            $text = trim(str_replace($mark, '', $item));
            if ($correct && preg_match('/^\*\*(.*)\*\*$/su', $text, $m)) {
                $text = trim($m[1]);
            }
            $out[] = ['text' => $text, 'correct' => $correct];
        }
        return $out;
    }

    private function join(array $blocks): string
    {
        return implode("\n\n", $blocks);
    }

    private function ucfirst(string $s): string
    {
        return mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
    }

    private function unquote(string $s): string
    {
        $s = trim($s);
        return preg_match('/^["“„](.*)["”“]$/su', $s, $m) ? trim($m[1]) : $s;
    }
}
