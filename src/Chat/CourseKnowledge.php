<?php

namespace App\Chat;

use App\Command\ImportCourseCommand;
use App\Entity\Lesson;
use App\Entity\User;
use App\Repository\CourseRepository;
use App\Repository\LessonProgressRepository;

/**
 * What the chat coach knows from the ZENOO Dog Wellness Course.
 *
 * knowledge(): the English lessons as compact text for the system prompt
 *   ({{course_knowledge}} in config/chatbot/system_prompt.md). Read from the database,
 *   so edits made in the Course studio reach the coach straight away.
 * progress(): one pet parent's finished lessons and written answers ({{course_progress}}).
 */
class CourseKnowledge
{
    /** Screens the coach learns from (the hook, quizzes and badges add little). */
    private const LAYOUTS = ['read' => 'The science', 'dog' => 'Do it with your dog', 'deeper' => 'Go deeper', 'vet' => null];

    public function __construct(
        private readonly CourseRepository $courses,
        private readonly LessonProgressRepository $progress,
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
}
