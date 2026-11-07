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
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "My dog": the pet parent's home after login. Shows the dog profile from the Bowl Score
 * quiz (score, breakdown, tips, daily plan), three lessons picked for the dog, the course
 * progress and the AI coach. Without a quiz result it invites them to take the quiz.
 */
#[IsGranted('ROLE_USER')]
final class DashboardController extends AbstractController
{
    public function __construct(
        private readonly DogProfileRepository $dogs,
        private readonly CourseRepository $courses,
        private readonly LessonProgressRepository $progress,
        private readonly LessonRecommender $recommender,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
    }

    #[Route('/dashboard', name: 'app_dashboard', methods: ['GET'])]
    public function index(Request $request): Response
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
            foreach ($lessons as $lesson) {
                if (empty($completed[$lesson->getId()])) {
                    $next = $lesson;
                    break;
                }
            }
            if ($dog) {
                $picks = $this->recommender->recommend($dog, $course, $completed);
            }
        }

        return $this->render('dashboard/index.html.twig', [
            'user' => $user,
            'dog' => $dog,
            'photo' => $dog ? $this->photo($dog) : null,
            'course' => $course,
            'picks' => $picks,
            'done' => $done,
            'total' => $total,
            'next' => $next,
            'streak' => $this->progress->getStreak($user),
            'coachQuestions' => $dog ? $this->coachQuestions($dog) : [],
        ]);
    }

    /** The course in the page's language (English as a fallback), if pet parents can see it. */
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

    /** The quiz's own breed photo (public/quiz/assets/photos), matched from the email photo URL. */
    private function photo(DogProfile $dog): ?string
    {
        if (preg_match('/zq-email-([a-z0-9-]+)\.jpg$/', (string) $dog->get('breed_photo_url', ''), $m)
            && is_file($this->projectDir . '/public/quiz/assets/photos/' . $m[1] . '.jpg')) {
            return '/quiz/assets/photos/' . $m[1] . '.jpg';
        }
        return null;
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
