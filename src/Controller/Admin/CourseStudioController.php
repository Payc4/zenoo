<?php

namespace App\Controller\Admin;

use App\Controller\CourseController;
use App\Course\CourseVoices;
use App\Course\LessonLayouts;
use App\Course\VoiceGenerator;
use App\Entity\Course;
use App\Entity\Lesson;
use App\Repository\CourseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Course studio: where the ZENOO team builds courses and edits lessons.
 * Lessons are edited slide by slide in a PowerPoint-style editor
 * (templates/admin/course_studio/editor.html.twig + assets/controllers/course_editor_controller.js).
 */
#[Route('/admin/course-studio')]
#[IsGranted('ROLE_ADMIN')]
final class CourseStudioController extends AbstractController
{
    private const AUDIO_DIR = '/public/uploads/lesson-audio';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CourseRepository $courses,
        private readonly SluggerInterface $slugger,
        private readonly VoiceGenerator $voiceGenerator,
        private readonly CourseVoices $courseVoices,
    ) {}

    #[Route('', name: 'admin_course_studio', methods: ['GET'])]
    public function index(): Response
    {
        $courses = $this->courses->findAllOrdered();
        $voices = [];
        foreach ($courses as $course) {
            $missing = $this->courseVoices->screens($course);
            $voices[$course->getId()] = [
                'missing' => \count($missing),
                'chars' => CourseVoices::characters($missing),
                'ready' => $this->voiceGenerator->isConfigured($course->getLocale()),
            ];
        }
        return $this->render('admin/course_studio/index.html.twig', [
            'courses' => $courses,
            'voices' => $voices,
            'voiceModel' => $this->voiceGenerator->model(),
        ]);
    }

    #[Route('/course/new', name: 'admin_course_studio_course_new', methods: ['POST'])]
    public function newCourse(Request $request): Response
    {
        $this->checkCsrf($request);
        $title = trim((string) $request->request->get('title')) ?: 'New course';
        $course = (new Course())
            ->setTitle(mb_substr($title, 0, 255))
            ->setSlug($this->uniqueSlug($title))
            ->setPosition(\count($this->courses->findAll()));
        $this->em->persist($course);
        $this->em->flush();
        $this->addFlash('success', 'Course created. Add your first lesson below.');
        return $this->redirectToRoute('admin_course_studio', ['_fragment' => 'course-' . $course->getId()]);
    }

    #[Route('/course/{id}/update', name: 'admin_course_studio_course_update', methods: ['POST'])]
    public function updateCourse(Course $course, Request $request): Response
    {
        $this->checkCsrf($request);
        $title = trim((string) $request->request->get('title'));
        if ($title !== '') {
            $course->setTitle(mb_substr($title, 0, 255));
        }
        $course->setDescription(trim((string) $request->request->get('description')) ?: null);
        $course->setIsPublished($request->request->getBoolean('published'));
        $course->setIsFree($request->request->getBoolean('free'));
        $course->setLocale((string) $request->request->get('locale', 'en'));
        $this->em->flush();
        $this->addFlash('success', 'Course saved.');
        return $this->redirectToRoute('admin_course_studio', ['_fragment' => 'course-' . $course->getId()]);
    }

    #[Route('/course/{id}/delete', name: 'admin_course_studio_course_delete', methods: ['POST'])]
    public function deleteCourse(Course $course, Request $request): Response
    {
        $this->checkCsrf($request);
        $this->em->remove($course);
        $this->em->flush();
        $this->addFlash('success', 'Course deleted.');
        return $this->redirectToRoute('admin_course_studio');
    }

    #[Route('/course/{id}/lesson/new', name: 'admin_course_studio_lesson_new', methods: ['POST'])]
    public function newLesson(Course $course, Request $request): Response
    {
        $this->checkCsrf($request);
        $title = trim((string) $request->request->get('title')) ?: 'Lesson ' . ($course->getLessons()->count() + 1);
        $lesson = (new Lesson())
            ->setTitle(mb_substr($title, 0, 255))
            ->setPosition($course->getLessons()->count())
            ->setSlides($request->request->get('template') === 'blank' ? [] : $this->standardSlides());
        $course->addLesson($lesson);
        $this->em->persist($lesson);
        $this->em->flush();
        return $this->redirectToRoute('admin_course_studio_lesson', ['id' => $lesson->getId()]);
    }

    #[Route('/lesson/{id}/move/{dir}', name: 'admin_course_studio_lesson_move', requirements: ['dir' => 'up|down'], methods: ['POST'])]
    public function moveLesson(Lesson $lesson, string $dir, Request $request): Response
    {
        $this->checkCsrf($request);
        $list = $lesson->getCourse()->getLessons()->toArray();
        $i = array_search($lesson, $list, true);
        $j = $dir === 'up' ? $i - 1 : $i + 1;
        if (isset($list[$j])) {
            [$list[$i], $list[$j]] = [$list[$j], $list[$i]];
        }
        foreach ($list as $pos => $l) {
            $l->setPosition($pos);
        }
        $this->em->flush();
        return $this->redirectToRoute('admin_course_studio', ['_fragment' => 'course-' . $lesson->getCourse()->getId()]);
    }

    #[Route('/lesson/{id}/duplicate', name: 'admin_course_studio_lesson_duplicate', methods: ['POST'])]
    public function duplicateLesson(Lesson $lesson, Request $request): Response
    {
        $this->checkCsrf($request);
        $course = $lesson->getCourse();
        $copy = (new Lesson())
            ->setTitle(mb_substr($lesson->getTitle() . ' (copy)', 0, 255))
            ->setSubtitle($lesson->getSubtitle())
            ->setDurationMinutes($lesson->getDurationMinutes())
            ->setPosition($course->getLessons()->count())
            ->setSlides(array_map(fn ($s) => ['id' => 's_' . bin2hex(random_bytes(4))] + $s, $lesson->getSlides()));
        $course->addLesson($copy);
        $this->em->persist($copy);
        $this->em->flush();
        return $this->redirectToRoute('admin_course_studio_lesson', ['id' => $copy->getId()]);
    }

    #[Route('/lesson/{id}/delete', name: 'admin_course_studio_lesson_delete', methods: ['POST'])]
    public function deleteLesson(Lesson $lesson, Request $request): Response
    {
        $this->checkCsrf($request);
        $courseId = $lesson->getCourse()->getId();
        $lesson->getCourse()->removeLesson($lesson);
        $this->em->remove($lesson);
        $this->em->flush();
        $this->addFlash('success', 'Lesson deleted.');
        return $this->redirectToRoute('admin_course_studio', ['_fragment' => 'course-' . $courseId]);
    }

    #[Route('/lesson/{id}', name: 'admin_course_studio_lesson', methods: ['GET'])]
    public function editor(Lesson $lesson): Response
    {
        return $this->render('admin/course_studio/editor.html.twig', [
            'lesson' => $lesson,
            'course' => $lesson->getCourse(),
            'layouts' => LessonLayouts::all(),
            'transitions' => LessonLayouts::TRANSITIONS,
            'voice_ready' => $this->voiceGenerator->isConfigured($lesson->getCourse()->getLocale()),
        ]);
    }

    #[Route('/lesson/{id}/save', name: 'admin_course_studio_lesson_save', methods: ['POST'])]
    public function save(Lesson $lesson, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('studio', $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['error' => 'Your session expired. Reload the page and try again.'], 400);
        }
        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return $this->json(['error' => 'Invalid data'], 400);
        }
        $title = trim((string) ($data['title'] ?? ''));
        $lesson->setTitle(mb_substr($title !== '' ? $title : 'Untitled lesson', 0, 255));
        $lesson->setSubtitle(mb_substr(trim((string) ($data['subtitle'] ?? '')), 0, 255) ?: null);
        $minutes = (int) ($data['durationMinutes'] ?? 0);
        $lesson->setDurationMinutes($minutes > 0 ? min($minutes, 600) : null);
        $lesson->setIsPublished((bool) ($data['isPublished'] ?? false));
        $lesson->setSlides(LessonLayouts::sanitizeSlides(\is_array($data['slides'] ?? null) ? $data['slides'] : []));
        $this->em->flush();

        return $this->json(['ok' => true, 'slides' => $lesson->getSlides(), 'savedAt' => (new \DateTimeImmutable())->format('H:i')]);
    }

    /** Renders unsaved slides with the real player, for the editor's phone preview. */
    #[Route('/lesson/{id}/preview', name: 'admin_course_studio_lesson_preview', methods: ['POST'])]
    public function preview(Lesson $lesson, Request $request, CourseController $courseController): Response
    {
        $data = json_decode((string) $request->request->get('payload'), true) ?: [];
        $slides = LessonLayouts::sanitizeSlides(\is_array($data['slides'] ?? null) ? $data['slides'] : []);
        $index = max(0, min((int) ($data['index'] ?? 0), \count($slides) - 1));

        $ctx = $courseController->context($lesson, null, [], $index, true);
        $response = $this->render('admin/course_studio/preview.html.twig', [
            'lesson' => $lesson,
            'slides' => $slides,
            'answers' => [],
            'ctx' => $ctx,
        ]);
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        return $response;
    }

    #[Route('/upload-audio', name: 'admin_course_studio_upload_audio', methods: ['POST'])]
    public function uploadAudio(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('studio', $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['error' => 'Your session expired. Reload the page and try again.'], 400);
        }
        $file = $request->files->get('audio');
        if (!$file || !$file->isValid()) {
            return $this->json(['error' => 'Upload failed. Files must be under 10 MB.'], 400);
        }
        $mime = (string) $file->getMimeType();
        $ext = strtolower($file->getClientOriginalExtension());
        if (!str_starts_with($mime, 'audio/') && !\in_array($ext, ['mp3', 'm4a', 'wav', 'ogg', 'aac'], true)) {
            return $this->json(['error' => 'Please upload an audio file (MP3, M4A, WAV or OGG).'], 400);
        }
        $ext = \in_array($ext, ['mp3', 'm4a', 'wav', 'ogg', 'aac'], true) ? $ext : 'mp3';
        $base = (string) $this->slugger->slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME))->lower()->slice(0, 40);
        $name = ($base ?: 'audio') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
        $dir = $this->getParameter('kernel.project_dir') . self::AUDIO_DIR;
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $file->move($dir, $name);

        return $this->json(['ok' => true, 'url' => '/uploads/lesson-audio/' . $name, 'name' => $file->getClientOriginalName()]);
    }

    /** Editor: speech for the script on screen (not saved until the lesson is saved, like an upload). */
    #[Route('/lesson/{id}/generate-voice', name: 'admin_course_studio_generate_voice', methods: ['POST'])]
    public function generateVoice(Lesson $lesson, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('studio', $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['error' => 'Your session expired. Reload the page and try again.'], 400);
        }
        $script = (string) (json_decode($request->getContent(), true)['script'] ?? '');
        $course = $lesson->getCourse();
        $number = 1 + (int) array_search($lesson, $course->getLessons()->toArray(), true);
        try {
            $url = $this->voiceGenerator->generate($script, $course->getLocale(), sprintf('%s-%s-lesson-%02d', $course->getLocale(), substr($course->getSlug(), 0, 30), $number));
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], 502);
        }
        return $this->json(['ok' => true, 'url' => $url]);
    }

    /**
     * Course list: makes the voice for the next Listen screen without audio and saves it.
     * The page calls this again and again until nothing is left, so no request runs for long.
     */
    #[Route('/course/{id}/voices/next', name: 'admin_course_studio_voices_next', methods: ['POST'])]
    public function nextVoice(Course $course, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('studio', $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['error' => 'Your session expired. Reload the page and try again.'], 400);
        }
        $todo = $this->courseVoices->screens($course);
        if (!$todo) {
            return $this->json(['ok' => true, 'remaining' => 0]);
        }
        try {
            $url = $this->courseVoices->generate($todo[0]['lesson'], $todo[0]['slide']);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage(), 'remaining' => \count($todo)], 502);
        }
        return $this->json(['ok' => true, 'url' => $url, 'lesson' => $todo[0]['lesson']->getTitle(), 'remaining' => \count($todo) - 1]);
    }

    // ───────────────────────── helpers ─────────────────────────

    private function checkCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('studio', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid form token. Reload the page and try again.');
        }
    }

    private function uniqueSlug(string $title): string
    {
        $base = (string) $this->slugger->slug($title)->lower()->slice(0, 80) ?: 'course';
        $slug = $base;
        $i = 2;
        while ($this->courses->findOneBy(['slug' => $slug])) {
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }

    /** The 9-screen ZENOO lesson format, with every box empty. */
    private function standardSlides(): array
    {
        $layouts = LessonLayouts::all();
        $slides = [];
        foreach (LessonLayouts::STANDARD as $layout) {
            $fields = [];
            foreach ($layouts[$layout]['fields'] as $def) {
                $fields[$def['key']] = $def['default'] ?? match ($def['type']) {
                    'list', 'choices', 'group' => [],
                    'checkbox' => false,
                    'number' => 0,
                    default => '',
                };
            }
            $slides[] = ['id' => 's_' . bin2hex(random_bytes(4)), 'layout' => $layout, 'transition' => 'slide', 'fields' => $fields];
        }
        return LessonLayouts::sanitizeSlides($slides);
    }
}
