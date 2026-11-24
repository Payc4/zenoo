<?php

namespace App\Controller;

use App\Command\ImportCourseCommand;
use App\Dog\LessonRecommender;
use App\Entity\Course;
use App\Entity\DogProfile;
use App\Entity\LessonProgress;
use App\Entity\User;
use App\Repository\CourseRepository;
use App\Repository\DogProfileRepository;
use App\Repository\LessonProgressRepository;
use App\Service\DogPhotoUploader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "My dog": the pet parent's home after login. Shows the dog profile from the Bowl Score
 * quiz (score, breakdown, tips, daily plan), three lessons picked for the dog, the course
 * progress and the AI coach. Without a quiz result it invites them to take the quiz.
 *
 * "Finish the profile" asks for the two things the coach needs first: the food (a label photo
 * in the chat) and a photo of the dog (uploaded here, DogPhotoUploader).
 */
#[IsGranted('ROLE_USER')]
final class DashboardController extends AbstractController
{
    public function __construct(
        private readonly DogProfileRepository $dogs,
        private readonly CourseRepository $courses,
        private readonly LessonProgressRepository $progress,
        private readonly LessonRecommender $recommender,
        private readonly DogPhotoUploader $dogPhotos,
        private readonly \App\Catalogue\PlanBuilder $plans,
        private readonly \App\Tracking\Funnel $funnel,
        private readonly EntityManagerInterface $em,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        private readonly \App\Quiz\ScoreRecalculator $rescorer,
    ) {
    }

    /**
     * "Today" (test report proposal 2): one next step, today's bowl, the log and the coach, in
     * about a screen and a half. Everything occasional about the dog is on its own tab (dogTab()).
     */
    #[Route('/dashboard', name: 'app_dashboard', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $ctx = $this->context($request);
        return $this->render('dashboard/index.html.twig', $ctx + ['nextStep' => $this->nextStep($ctx)]);
    }

    /** The dog's own tab (proposal 3): score and history, plan, trends, notes, vet report, sharing, removal. */
    #[Route('/dashboard/dog', name: 'app_dog_tab', methods: ['GET'])]
    public function dogTab(Request $request): Response
    {
        $ctx = $this->context($request);
        if (!$ctx['dog']) {
            return $this->redirectToRoute('app_dashboard');
        }
        if ($ctx['zenooPlan'] && !$request->getSession()->get('funnel_plan_view')) {
            $request->getSession()->set('funnel_plan_view', true);
            $this->funnel->record('plan_view');
        }
        return $this->render('dashboard/dog.html.twig', $ctx);
    }

    /** What both pages need. */
    private function context(Request $request): array
    {
        /** @var User $user */
        $user = $this->getUser();
        $dog = $this->dogs->findForUser($user);
        $course = $this->courseFor($request->getLocale());

        $picks = [];
        $done = 0;
        $total = 0;
        $next = null;
        if ($course) {
            $progress = $this->progress->findForCourse($user, $course);
            $completed = array_map(fn (LessonProgress $p) => $p->isCompleted(), $progress);
            $lessons = $course->getPublishedLessons();
            $total = \count($lessons);
            $done = \count(array_filter($completed));
            foreach ($lessons as $i => $lesson) {
                if (empty($completed[$lesson->getId()])) {
                    $next = ['lesson' => $lesson, 'number' => $i + 1];
                    break;
                }
            }
            if ($dog) {
                $picks = $this->recommender->recommend($dog, $course, $completed);
            }
        }

        return [
            'user' => $user,
            'dog' => $dog,
            'photo' => $dog ? $this->photo($dog) : null,
            'ownPhoto' => $dog ? $this->dogPhotos->url($dog) !== null : false,
            'course' => $course,
            'picks' => $picks,
            'done' => $done,
            'total' => $total,
            'next' => $next,
            'streak' => $this->progress->getStreak($user),
            'coachQuestions' => $dog ? $this->coachQuestions($dog) : [],
            'zenooPlan' => $dog ? $this->plans->forDog($dog) : null,
            'shopUrl' => $this->plans->shopUrl('dashboard'),
            'dogs' => $this->dogs->findAllForUser($user),
            'logged' => $request->getSession()->getFlashBag()->get('logged')[0] ?? null,
        ];
    }

    /**
     * The one next step on Today, with the screen's only orange button. In order: the food (the
     * coach needs it), a check-in the coach planned, a weigh-in that's due (two weeks), the monthly
     * check, then the next lesson.
     *
     * @return array{kind: string, title: string, text: string, cta: string, url: string, params?: array}|null
     */
    private function nextStep(array $ctx): ?array
    {
        $dog = $ctx['dog'];
        if (!$dog) {
            return ['kind' => 'quiz', 'title' => 'How is your dog really eating?', 'text' => 'Take the free 3-minute check. Your dog’s score, plan and lessons appear here.', 'cta' => 'Take the free check', 'url' => $this->generateUrl('app_quiz')];
        }
        $p = ['%dog%' => $dog->getName()];
        if (!$dog->getFood()) {
            return ['kind' => 'food', 'title' => 'Show the coach what %dog% eats', 'text' => 'A photo of the food label, or its name. Then the coach’s tips fit %dog%.', 'cta' => '📷 Snap the food label', 'url' => $this->generateUrl('app_chat', ['photo' => 1]), 'params' => $p];
        }
        $today = (new \DateTimeImmutable())->format('Y-m-d');
        foreach ($dog->getFollowUps() as $f) {
            if (!$f['done'] && $f['due'] <= $today) {
                return ['kind' => 'checkin', 'title' => 'A check-in from the coach', 'text' => $f['question'], 'cta' => 'Answer in the chat', 'url' => $this->generateUrl('app_chat'), 'params' => $p];
            }
        }
        $weight = $dog->latestLog('weight');
        if ($weight && (new \DateTimeImmutable($weight['d']))->diff(new \DateTimeImmutable($today))->days >= 14) {
            return ['kind' => 'weigh', 'title' => 'Time to weigh %dog%', 'text' => 'Two weeks since the last weight. It shows whether the plan is working.', 'cta' => 'Add the weight', 'url' => '#log-weight', 'params' => $p];
        }
        if ($dog->daysSinceScore(new \DateTimeImmutable()) >= 30) {
            return ['kind' => 'check', 'title' => 'Time for %dog%’s monthly check', 'text' => 'A month since the Bowl Score. Take it again to see what has changed.', 'cta' => 'Retake the check', 'url' => $this->generateUrl('app_quiz', ['dog' => $dog->getId()]), 'params' => $p];
        }
        $pick = $ctx['picks'][0] ?? null;
        $lesson = $pick ? ['lesson' => $pick['lesson'], 'number' => $pick['number']] : $ctx['next'];
        if ($lesson && $ctx['course']) {
            return ['kind' => 'lesson', 'title' => $lesson['lesson']->getTitle(), 'text' => '', 'cta' => 'Start the lesson', 'url' => $this->generateUrl('app_course_lesson', ['slug' => $ctx['course']->getSlug(), 'id' => $lesson['lesson']->getId()]), 'params' => $p, 'lesson' => $lesson];
        }
        return null;
    }

    /** The dog's own photo ("Finish the profile", or the camera on the dashboard photo). */
    #[Route('/dashboard/dog-photo', name: 'app_dog_photo', methods: ['POST'])]
    public function dogPhoto(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $dog = $this->dogs->findForUser($user);
        if (!$dog || !$this->isCsrfTokenValid('dog-photo', (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('app_dog_tab');
        }
        $file = $request->files->get('photo');
        if (!$file instanceof UploadedFile) {
            $this->addFlash('error', 'Choose a photo first.');
            return $this->redirectToRoute('app_dog_tab');
        }
        try {
            $this->dogPhotos->store($dog, $file);
            $this->em->flush();
            $this->addFlash('success', 'Lovely! The photo is saved.');
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }
        return $this->redirectToRoute('app_dog_tab');
    }

    /** The dog photo, served to its owner only (test report M11): nginx refuses /uploads/private/. */
    #[Route('/dashboard/dog-photo/{name}', name: 'app_dog_photo_file', requirements: ['name' => 'dog_\d+_[a-f0-9]+\.jpg'], methods: ['GET'])]
    public function dogPhotoFile(string $name): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        foreach ($this->dogs->findAllForUser($user) as $dog) {
            if ($dog->getPhoto() === $name && ($file = $this->dogPhotos->path($dog))) {
                $response = new BinaryFileResponse($file, 200, ['Content-Type' => 'image/jpeg', 'X-Content-Type-Options' => 'nosniff']);
                $response->setPrivate();
                $response->setMaxAge(86400);
                return $response;
            }
        }
        throw $this->createNotFoundException();
    }

    /** The course in the page's language (English as a fallback), if pet parents can see it. */
    /** "2-week starter": counts the click (for measurement), then the Shopify cart with the packs. */
    #[Route('/dashboard/plan/{option}', name: 'app_plan_cart', requirements: ['option' => 'topper|half|full'], methods: ['GET'])]
    public function planCart(string $option): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $dog = $this->dogs->findForUser($user);
        $plan = $dog ? $this->plans->forDog($dog) : null;
        if ($plan === null) {
            return $this->redirectToRoute('app_dashboard');
        }
        $dog->recordPlanClick($option, new \DateTimeImmutable());
        $this->em->flush();
        $this->funnel->record('plan_cart', \App\Tracking\Attribution::label($user->getAttribution()));
        return $this->redirect($plan['options'][$option]['cart_url']);
    }

    /** Several dogs: choose which one the dashboard, plan and coach are about. */
    #[Route('/dashboard/dog/{id}/select', name: 'app_dog_select', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function selectDog(int $id, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        foreach ($this->dogs->findAllForUser($user) as $dog) {
            if ($dog->getId() === $id && $this->isCsrfTokenValid('dog-select', (string) $request->request->get('_token'))) {
                $user->setActiveDogId($id);
                $this->em->flush();
            }
        }
        $back = (string) $request->request->get('back', '');
        // Back to the same kind of page, now about the other dog.
        $to = match (true) {
            str_starts_with($back, '/chat') => $this->generateUrl('app_chat'),
            str_starts_with($back, '/courses') => $this->generateUrl('app_courses'),
            $back === '/dashboard/dog' => $this->generateUrl('app_dog_tab'),
            default => $this->generateUrl('app_dashboard'),
        };
        return $this->redirect($to);
    }

    /** Removes one of several dogs, with its photo. The last dog stays (delete the account instead). */
    #[Route('/dashboard/dog/{id}/remove', name: 'app_dog_remove', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function removeDog(int $id, Request $request, \Symfony\Contracts\Translation\TranslatorInterface $translator, \App\Repository\ChatConversationRepository $chats, \App\Chat\ChatFiles $chatFiles): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $dogs = $this->dogs->findAllForUser($user);
        foreach ($dogs as $dog) {
            if ($dog->getId() === $id && \count($dogs) > 1 && $this->isCsrfTokenValid('dog-remove' . $id, (string) $request->request->get('_token'))) {
                $this->dogPhotos->remove($dog);
                // Its chat goes with it (one chat per pet).
                if ($chat = $chats->findForDog($dog)) {
                    $chatFiles->removeForMessages($user, $chat->getMessages()->toArray());
                    $this->em->remove($chat);
                }
                $name = $dog->getName();
                $this->em->remove($dog);
                $user->setActiveDogId(null);
                $this->em->flush();
                $this->addFlash('success', $translator->trans('%dog% has been removed from your account.', ['%dog%' => $name]));
            }
        }
        return $this->redirectToRoute('app_dashboard');
    }

    /** Roadmap 11: a one-page summary to print or save as PDF and take to the vet. */
    #[Route('/dashboard/vet-report', name: 'app_vet_report', methods: ['GET'])]
    public function vetReport(\App\Chat\CheckIns $checkIns): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $dog = $this->dogs->findForUser($user);
        if (!$dog) {
            return $this->redirectToRoute('app_dashboard');
        }
        $facts = \App\Chat\Calculators::dogFacts($dog);
        return $this->render('dashboard/vet_report.html.twig', [
            'user' => $user,
            'dog' => $dog,
            'facts' => $facts,
            'energy' => $facts['weight_kg'] ? \App\Chat\Calculators::run(['operation' => 'daily_energy'], $dog) : null,
            'checkins' => $checkIns->forCoach($dog),
            'photo' => $this->photo($dog),
        ]);
    }

    /** Adds today's value to one of the trackers (weight, body shape, stool, itch). */
    #[Route('/dashboard/tracker', name: 'app_dog_tracker', methods: ['POST'])]
    public function tracker(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $dog = $this->dogs->findForUser($user);
        if ($dog && $this->isCsrfTokenValid('tracker', (string) $request->request->get('_token'))) {
            $value = str_replace(',', '.', (string) $request->request->get('value', ''));
            $type = (string) $request->request->get('type');
            if (is_numeric($value) && $dog->addLog($type, (float) $value, new \DateTimeImmutable(), 'owner')) {
                // A new weight or body shape: the Bowl Score and plan are worked out again (notice on Today).
                if (\in_array($type, ['weight', 'body'], true)) {
                    $this->rescorer->recalculate($dog, $type, new \DateTimeImmutable());
                }
                $this->em->flush();
                $this->addFlash('logged', $type); // "Saved just now · Undo" on that tile
            } else {
                $this->addFlash('error', 'That value doesn’t look right. Please check it.');
            }
        }
        return $this->redirect($this->generateUrl('app_dashboard') . '#log');
    }

    /** Undo the entry just added (proposal 2: Undo on the newest entry). */
    #[Route('/dashboard/tracker/undo', name: 'app_dog_tracker_undo', methods: ['POST'])]
    public function trackerUndo(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $dog = $this->dogs->findForUser($user);
        if ($dog && $this->isCsrfTokenValid('tracker', (string) $request->request->get('_token'))
            && $dog->undoLog($type = (string) $request->request->get('type'), new \DateTimeImmutable())) {
            if (\in_array($type, ['weight', 'body'], true)) {
                $this->rescorer->recalculate($dog, $type, new \DateTimeImmutable()); // back to the score before
            }
            $this->em->flush();
            $this->addFlash('success', 'Removed.');
        }
        return $this->redirect($this->generateUrl('app_dashboard') . '#log');
    }

    /** Closes the "Bowl Score 47 → 52" notice on Today and the dog's tab. */
    #[Route('/dashboard/score-change/close', name: 'app_score_change_close', methods: ['POST'])]
    public function closeScoreChange(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $dog = $this->dogs->findForUser($user);
        if ($dog && $this->isCsrfTokenValid('score-change', (string) $request->request->get('_token'))) {
            $dog->dismissScoreChange();
            $this->em->flush();
        }
        $back = (string) $request->request->get('back');
        return $this->redirect($back === 'dog' ? $this->generateUrl('app_dog_tab') : $this->generateUrl('app_dashboard'));
    }

    /** The owner adds a fact about their dog, or removes one the coach saved (the dog record is theirs to edit). */
    #[Route('/dashboard/dog-notes', name: 'app_dog_notes', methods: ['POST'])]
    public function dogNotes(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $dog = $this->dogs->findForUser($user);
        if (!$dog || !$this->isCsrfTokenValid('dog-notes', (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('app_dashboard');
        }
        if ($request->request->has('remove')) {
            if ($dog->removeNote((int) $request->request->get('remove'))) {
                $this->em->flush();
                $this->addFlash('success', 'Removed from your dog’s profile.');
            }
        } elseif ($dog->addNote('other', (string) $request->request->get('text', ''), new \DateTimeImmutable(), null, 'owner')) {
            $this->em->flush();
            $this->addFlash('success', 'Added to your dog’s profile.');
        }
        return $this->redirect($this->generateUrl('app_dog_tab') . '#knows-h');
    }

    private function courseFor(string $locale): ?Course
    {
        foreach ([$locale, 'en'] as $lang) {
            $slug = ImportCourseCommand::COURSES[$lang]['slug'] ?? null;
            $course = $slug ? $this->courses->findOneBy(['slug' => $slug]) : null;
            if ($course && ($course->isPublished() || $this->isGranted('ROLE_ADMIN'))) {
                return $course;
            }
        }
        return null;
    }

    /** The dog's own photo, else the quiz's breed photo (public/quiz/assets/photos), matched from the email photo URL. */
    private function photo(DogProfile $dog): ?string
    {
        return $this->dogPhotos->displayUrl($dog);
    }

    /**
     * Starter questions for the coach, as translation keys with %dog%.
     *
     * @return list<string>
     */
    private function coachQuestions(DogProfile $dog): array
    {
        $q = ['How much should %dog% eat a day?'];
        $signs = (array) $dog->get('signs', []);
        if (\in_array('stools', $signs, true) || \in_array('gas', $signs, true)) {
            $q[] = 'What can help %dog%’s digestion?';
        } elseif (\in_array('itching', $signs, true) || \in_array('coat', $signs, true)) {
            $q[] = 'What can help %dog%’s skin and coat?';
        } elseif ((int) $dog->get('bcs', 5) >= 6) {
            $q[] = 'How can %dog% lose weight safely?';
        } else {
            $q[] = 'How do I switch %dog% to fresh food?';
        }
        $q[] = 'What should I do first to raise %dog%’s Bowl Score?';
        return $q;
    }
}
