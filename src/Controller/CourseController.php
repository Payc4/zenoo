<?php

namespace App\Controller;

use App\Entity\Course;
use App\Entity\Lesson;
use App\Entity\LessonProgress;
use App\Entity\Purchase;
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
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'app_courses', methods: ['GET'])]
    public function index(Request $request): Response
    {
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
                'canAccess' => $this->canAccess($course),
                'next' => $this->nextLesson($lessons, $progress),
            ];
        }

        return $this->render('course/index.html.twig', [
            'items' => $list,
            'streak' => $user instanceof User ? $this->progressRepo->getStreak($user) : 0,
        ]);
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
        if (!$this->canAccess($course)) {
            $this->addFlash('info', 'This course is for ZENOO customers.');
            return $this->redirectToRoute('app_courses');
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
    public function saveProgress(Lesson $lesson, Request $request): JsonResponse
    {
        $user = $this->getUser();
        $course = $lesson->getCourse();
        if (!$user instanceof User || !$course || !$this->isVisible($course, $lesson) || !$this->canAccess($course)) {
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

    private function isVisible(Course $course, Lesson $lesson): bool
    {
        return $this->isGranted('ROLE_ADMIN') || ($course->isPublished() && $lesson->isPublished());
    }

    /** Admins and free courses; otherwise anyone with a paid order. */
    private function canAccess(Course $course): bool
    {
        if ($this->isGranted('ROLE_ADMIN') || $course->isFree()) {
            return true;
        }
        $user = $this->getUser();
        if (!$user instanceof User) {
            return false;
        }
        foreach ($user->getPurchases() as $purchase) {
            if ($purchase->getStatus() === Purchase::STATUS_COMPLETED) {
                return true;
            }
        }
        return false;
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
