<?php

namespace App\Course;

use App\Entity\Course;
use App\Entity\Lesson;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The Listen screens of a course that still need a voice, and making one with ElevenLabs.
 * Used by the Course studio ("Generate voices") and app:course:voice.
 */
final class CourseVoices
{
    public function __construct(
        private readonly VoiceGenerator $voices,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Listen screens with a script, in lesson order: without audio, or all of them with $all.
     *
     * @return list<array{lesson: Lesson, slide: string, chars: int}>
     */
    public function screens(Course $course, bool $all = false, ?int $lessonNumber = null): array
    {
        $out = [];
        foreach ($course->getLessons() as $n => $lesson) {
            if ($lessonNumber !== null && $n + 1 !== $lessonNumber) {
                continue;
            }
            foreach ($lesson->getSlides() as $slide) {
                $f = $slide['fields'] ?? [];
                if (($slide['layout'] ?? '') !== 'listen' || trim((string) ($f['script'] ?? '')) === '') {
                    continue;
                }
                if ($all || trim((string) ($f['audio'] ?? '')) === '') {
                    $out[] = ['lesson' => $lesson, 'slide' => $slide['id'], 'chars' => mb_strlen(VoiceGenerator::speechText($f['script']))];
                }
            }
        }
        return $out;
    }

    /** Characters ElevenLabs would bill for these screens (one credit per character on most models). */
    public static function characters(array $screens): int
    {
        return array_sum(array_column($screens, 'chars'));
    }

    /** Makes the voice for one screen and saves it on the lesson. Returns the audio URL. */
    public function generate(Lesson $lesson, string $slideId): string
    {
        $course = $lesson->getCourse();
        $slides = $lesson->getSlides();
        foreach ($slides as $i => $slide) {
            if (($slide['id'] ?? null) !== $slideId) {
                continue;
            }
            $number = 1 + (int) array_search($lesson, $course->getLessons()->toArray(), true);
            $url = $this->voices->generate(
                (string) ($slide['fields']['script'] ?? ''),
                $course->getLocale(),
                sprintf('%s-%s-lesson-%02d', $course->getLocale(), substr($course->getSlug(), 0, 30), $number),
            );
            $slides[$i]['fields']['audio'] = $url;
            $slides[$i]['fields']['ai_voice'] = true;
            $lesson->setSlides(LessonLayouts::sanitizeSlides($slides));
            $this->em->flush();
            return $url;
        }
        throw new \RuntimeException('That screen no longer exists. Reload the page.');
    }
}
