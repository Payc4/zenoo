<?php

namespace App\Controller;

use App\Entity\Course;
use App\Entity\Lesson;
use App\Entity\LessonProgress;
use App\Course\LessonAccess;
use App\Course\LessonLayouts;
use App\Entity\User;
use App\Repository\CourseRepository;
use App\Repository\LessonProgressRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Learner side of courses: course list, lesson player and progress saving.
 * Lessons are edited in the admin Course studio (/admin/course-studio).
 * The whole /courses area needs a login (config/packages/security.yaml).
 */
#[Route('/courses')]
final class CourseController extends AbstractController
{
    public function __construct(
        private readonly CourseRepository $courses,
        private readonly LessonProgressRepository $progressRepo,
        private readonly \App\Dog\LessonRecommender $recommender,
        private readonly \App\Repository\DogProfileRepository $dogs,
        private readonly EntityManagerInterface $em,
        private readonly LessonAccess $access,
    ) {}

    #[Route('', name: 'app_courses', methods: ['GET'])]
    public function index(Request $request): Response
    {
        // Visitors get a short preview and two ways in (test report N5).
        if (!$this->getUser() instanceof User) {
            $course = $this->courseFor($request->getLocale());
            return $this->render('preview/feature.html.twig', [
                'feature' => 'course',
                'lessons' => $course ? \array_slice($course->getPublishedLessons(), 0, 5) : [],
                'lessonCount' => $course ? \count($course->getPublishedLessons()) : 30,
            ]);
        }
        $user = $this->getUser();
        $isAdmin = $this->isGranted('ROLE_ADMIN');
        $courses = $this->courses->findAllOrdered(!$isAdmin);
        // Everyone sees the courses in the language picked in the header (admins also see that
        // language's drafts). With none in that language, the English ones, else whatever exists.
        // Admins check other languages by switching the language, or in the Course studio.
        $in = fn (string $locale) => array_values(array_filter($courses, fn (Course $c) => $c->getLocale() === $locale));
        $courses = $in($request->getLocale()) ?: $in('en') ?: $courses;
        $list = [];
        foreach ($courses as $course) {
            $lessons = $isAdmin ? $course->getLessons()->toArray() : $course->getPublishedLessons();
            $progress = $user instanceof User ? $this->progressRepo->findForCourse($user, $course) : [];
            $done = \count(array_filter($progress, fn (LessonProgress $p) => $p->isCompleted()));
            $list[] = [
                'course' => $course,
                'lessons' => $lessons,
                'progress' => $progress,
                'done' => $done,
                'locked' => array_values(array_map(fn (Lesson $l) => $l->getId(), array_filter($lessons, fn (Lesson $l) => !$this->canOpen($l)))),
                'next' => $this->nextLesson($lessons, $progress),
            ];
        }
        // "Picked for Max" (moved here from the dashboard): one lesson per concern, with the reason.
        $dog = $user instanceof User ? $this->dogs->findForUser($user) : null;
        $picks = [];
        if ($dog && $list) {
            $completed = array_map(fn (LessonProgress $p) => $p->isCompleted(), $list[0]['progress']);
            $picks = $this->recommender->recommend($dog, $list[0]['course'], $completed);
        }
        $unlock = $this->access->unlockProduct();
        $anyLocked = array_filter($list, fn (array $i) => $i['locked'] !== []);

        return $this->render('course/index.html.twig', [
            'items' => $list,
            'dog' => $dog,
            'picks' => $picks,
            // The "unlock all lessons" box, shown while this person still has locked lessons.
            'unlock' => $anyLocked ? $unlock : null,
            'unlockCancelled' => $request->query->has('cancelled'),
            'streak' => $user instanceof User ? $this->progressRepo->getStreak($user) : 0,
        ]);
    }

    /**
     * "Lesson 15" in the reader's language: the coach links lessons by number (/courses/lesson/15),
     * because each language's course has its own lesson addresses.
     */
    #[Route('/lesson/{n}', name: 'app_course_lesson_number', requirements: ['n' => '\d+'], methods: ['GET'])]
    public function lessonNumber(int $n, Request $request): Response
    {
        $courses = $this->courses->findAllOrdered(true);
        $in = fn (string $locale) => array_values(array_filter($courses, fn (Course $c) => $c->getLocale() === $locale));
        foreach ([$in($request->getLocale()), $in('en')] as $list) {
            $lessons = $list ? $list[0]->getLessons()->toArray() : [];
            if (isset($lessons[$n - 1])) {
                return $this->redirectToRoute('app_course_lesson', ['slug' => $list[0]->getSlug(), 'id' => $lessons[$n - 1]->getId()]);
            }
        }
        return $this->redirectToRoute('app_courses');
    }

    #[Route('/{slug}/lesson/{id}', name: 'app_course_lesson', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function lesson(string $slug, Lesson $lesson): Response
    {
        $course = $lesson->getCourse();
        if (!$course || $course->getSlug() !== $slug || !$this->isVisible($course, $lesson)) {
            throw $this->createNotFoundException();
        }
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }
        if (!$this->canOpen($lesson)) {
            $this->addFlash('info', 'This lesson is locked. Unlock all lessons to open it.');
            return $this->redirect($this->generateUrl('app_courses') . '#unlock');
        }

        $progress = $this->progressRepo->findOneFor($user, $lesson);
        $answers = $progress?->getAnswers() ?? [];
        $start = $progress && !$progress->isCompleted() ? min($progress->getCurrentSlide(), max(0, $lesson->getSlideCount() - 1)) : 0;

        return $this->render('course/lesson.html.twig', [
            'lesson' => $lesson,
            'course' => $course,
            'slides' => $lesson->getSlides(),
            'answers' => $answers,
            'ctx' => $this->context($lesson, $user, $answers, $start, false),
        ]);
    }

    #[Route('/progress/{id}', name: 'app_course_progress', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function saveProgress(Lesson $lesson, Request $request, \App\Tracking\Funnel $funnel): JsonResponse
    {
        $user = $this->getUser();
        $course = $lesson->getCourse();
        if (!$user instanceof User || !$course || !$this->isVisible($course, $lesson) || !$this->canOpen($lesson)) {
            return $this->json(['error' => 'Not allowed'], 403);
        }
        if (!$this->isCsrfTokenValid('lesson' . $lesson->getId(), $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['error' => 'Invalid token'], 400);
        }

        $data = json_decode($request->getContent(), true) ?: [];
        $progress = $this->progressRepo->findOneFor($user, $lesson) ?? new LessonProgress($user, $lesson);

        if (isset($data['slide'])) {
            $progress->setCurrentSlide(min((int) $data['slide'], max(0, $lesson->getSlideCount() - 1)));
        }

        $validIds = array_column($lesson->getSlides(), 'id');
        foreach (\is_array($data['answers'] ?? null) ? $data['answers'] : [] as $slideId => $a) {
            if (!\in_array($slideId, $validIds, true) || !\is_array($a)) {
                continue;
            }
            $clean = [];
            if (isset($a['quiz']) && \is_array($a['quiz'])) {
                foreach (array_slice($a['quiz'], 0, 10, true) as $qi => $q) {
                    if (\is_array($q)) {
                        $clean['quiz'][(int) $qi] = ['choice' => (int) ($q['choice'] ?? 0), 'correct' => (bool) ($q['correct'] ?? false)];
                    }
                }
            }
            if (isset($a['text']) && \is_array($a['text'])) {
                foreach (array_slice($a['text'], 0, 5, true) as $ti => $t) {
                    $clean['text'][(int) $ti] = mb_substr((string) $t, 0, 2000);
                }
            }
            if (isset($a['done'])) {
                $clean['done'] = (bool) $a['done'];
            }
            // A stool score written in a lesson ("Score: 3 …" on a 1–7 poop-score prompt) goes to the
            // dog's stool tracker too (test report M5).
            if (isset($clean['text'])) {
                $this->logStoolScore($user, $lesson, $slideId, $clean['text']);
            }
            if ($clean) {
                $existing = $progress->getAnswer($slideId);
                foreach ($clean as $k => $v) {
                    $existing[$k] = \is_array($v) ? array_replace($existing[$k] ?? [], $v) : $v;
                }
                $progress->setAnswer($slideId, $existing);
            }
        }

        if (!empty($data['complete']) && !$progress->isCompleted()) {
            $progress->setCompletedAt(new \DateTimeImmutable());
            $funnel->record('lesson_done', \App\Tracking\Attribution::label($user->getAttribution()));
        }

        $this->em->persist($progress);
        $this->em->flush();

        return $this->json([
            'ok' => true,
            'completed' => $progress->isCompleted(),
            'streak' => $this->progressRepo->getStreak($user),
            'number' => $lesson->getNumber(),
            'lessonCount' => \count($course->getPublishedLessons()) ?: \count($course->getLessons()),
            'next' => $this->nextUrl($lesson),
        ]);
    }

    // ───────────────────────── helpers ─────────────────────────

    /** Builds what the slide templates need besides the slide itself. */
    public function context(Lesson $lesson, ?User $user, array $answers, int $start, bool $preview): array
    {
        $course = $lesson->getCourse();
        $published = $course?->getPublishedLessons() ?? [];

        return [
            'preview' => $preview,
            'start' => $start,
            'number' => $lesson->getNumber(),
            'lessonCount' => \count($published) ?: \count($course?->getLessons() ?? []),
            'streak' => $user && !$preview ? $this->progressRepo->getStreak($user) : 0,
            'locale' => $course?->getLocale() ?? 'en',
            'ui' => LessonLayouts::ui($course?->getLocale() ?? 'en'),
        ];
    }

    /** @param array<int, string> $texts the answers to a write slide's prompts */
    private function logStoolScore(User $user, Lesson $lesson, string $slideId, array $texts): void
    {
        foreach ($lesson->getSlides() as $slide) {
            if (($slide['id'] ?? null) !== $slideId) {
                continue;
            }
            foreach ($texts as $i => $text) {
                $prompt = (string) ($slide['fields']['prompts'][$i]['instructions'] ?? '');
                if (!preg_match('/1\s*[–-]\s*7/u', $prompt) || !preg_match('/(?<!\d|\d[.,])([1-7])(?!\d|[.,]\d)/u', $text, $m)) {
                    continue;
                }
                $dog = $this->dogs->findForUser($user);
                if ($dog && $dog->addLog('stool', (float) $m[1], new \DateTimeImmutable(), 'owner')) {
                    $this->em->persist($dog);
                }
            }
        }
    }

    /** The published course in this language, else the English one. */
    private function courseFor(string $locale): ?Course
    {
        $courses = $this->courses->findAllOrdered(true);
        foreach ([$locale, 'en'] as $l) {
            foreach ($courses as $c) {
                if ($c->getLocale() === $l) {
                    return $c;
                }
            }
        }
        return $courses[0] ?? null;
    }

    private function isVisible(Course $course, Lesson $lesson): bool
    {
        return $this->isGranted('ROLE_ADMIN') || ($course->isPublished() && $lesson->isPublished());
    }

    /** Open lessons for everyone; locked ones for admins and people who bought the lesson unlock. */
    private function canOpen(Lesson $lesson): bool
    {
        $user = $this->getUser();

        return $this->access->canOpen($lesson, $user instanceof User ? $user : null, $this->isGranted('ROLE_ADMIN'));
    }

    /** @param Lesson[] $lessons @param array<int, LessonProgress> $progress */
    private function nextLesson(array $lessons, array $progress): ?Lesson
    {
        foreach ($lessons as $l) {
            if (!isset($progress[$l->getId()]) || !$progress[$l->getId()]->isCompleted()) {
                return $l;
            }
        }
        return null;
    }

    private function nextUrl(Lesson $lesson): string
    {
        $course = $lesson->getCourse();
        $list = $this->isGranted('ROLE_ADMIN') ? $course->getLessons()->toArray() : $course->getPublishedLessons();
        $found = false;
        foreach ($list as $l) {
            if ($found) {
                return $this->generateUrl('app_course_lesson', ['slug' => $course->getSlug(), 'id' => $l->getId()]);
            }
            $found = $l === $lesson;
        }
        return $this->generateUrl('app_courses');
    }
}
