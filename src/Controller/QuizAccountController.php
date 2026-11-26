<?php

namespace App\Controller;

use App\Dog\QuizResult;
use App\Entity\QuizSubmission;
use App\Entity\User;
use App\Repository\DogProfileRepository;
use App\Repository\QuizSubmissionRepository;
use App\Repository\UserRepository;
use App\Security\LoginFormAuthenticator;
use App\Service\EmailService;
use App\Service\KlaviyoProfiles;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Quiz → account.
 *
 * 1. When the quiz finishes, public/quiz/app.js posts the result here (POST /quiz/submit).
 *    - Logged in: the dog profile is saved to their account straight away.
 *    - Not logged in: the result waits in a QuizSubmission and the visitor gets a personal
 *      confirmation link: in the site's own email, and/or (when KLAVIYO_PRIVATE_KEY is set) written
 *      to their Klaviyo profile as "dashboard_link" for the Klaviyo quiz flow's email button.
 *      QUIZ_SITE_CONFIRM_EMAIL=false leaves it to Klaviyo alone (the site email is then only a
 *      fallback for when Klaviyo can't be reached).
 * 2. The link (GET /quiz/confirm/{token}) creates the account (or finds the existing one for
 *    that email), saves the dog profile, logs them in and opens the dog dashboard. Accounts
 *    created this way have no password yet; the dashboard asks them to choose one.
 */
final class QuizAccountController extends AbstractController
{
    private const PER_EMAIL_PER_HOUR = 3;
    private const PER_IP_PER_HOUR = 10;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly QuizSubmissionRepository $submissions,
        private readonly DogProfileRepository $dogs,
        private readonly UserRepository $users,
        private readonly EmailService $emails,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
        private readonly KlaviyoProfiles $klaviyo,
        private readonly \App\Tracking\Attribution $attribution,
        private readonly \App\Tracking\Funnel $funnel,
        private readonly \App\Service\PlanEmails $planEmails,
        private readonly \App\Quiz\QuizAccounts $accounts,
        private readonly \App\Payment\Subscriptions $subscriptions,
        #[Autowire('%kernel.secret%')] private readonly string $secret,
        #[Autowire('%env(bool:default:app.quiz.site_confirm_default:QUIZ_SITE_CONFIRM_EMAIL)%')] private readonly bool $siteConfirmEmail = true,
    ) {
    }

    #[Route('/quiz/submit', name: 'app_quiz_submit', methods: ['POST'])]
    public function submit(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('quiz', (string) $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['error' => $this->translator->trans('Please reload the page and try again.')], 400);
        }
        if (\strlen($request->getContent()) > 40000) {
            return $this->json(['error' => $this->translator->trans('Too much data.')], 413);
        }
        $body = json_decode($request->getContent(), true);
        $data = \is_array($body) ? QuizResult::clean($body['properties'] ?? null) : null;
        $email = \is_array($body) ? mb_strtolower(trim((string) ($body['email'] ?? ''))) : '';
        $firstName = \is_array($body) ? trim(strip_tags((string) ($body['first_name'] ?? ''))) : '';
        $locale = \App\Locale::isSupported($body['locale'] ?? null) ? $body['locale'] : $request->getLocale();
        if ($data === null) {
            return $this->json(['error' => $this->translator->trans('Invalid quiz result.')], 400);
        }

        // The quiz's own answers, so a retake can start from them (N2). Not the email.
        $answers = \is_array($body) && \is_array($body['answers'] ?? null) ? $body['answers'] : null;

        $user = $this->getUser();
        if ($user instanceof User) {
            // Which dog: "Retake the quiz for Max" sends its id, "+ Add another dog" says new. A plain
            // quiz with a name already on the account asks first: update that dog or add a second one (F1).
            $dogId = \is_array($body) && is_numeric($body['dog_id'] ?? null) ? (int) $body['dog_id'] : null;
            $asNew = \is_array($body) && !empty($body['new']);
            if ($dogId !== null && !\in_array($dogId, array_map(fn ($d) => $d->getId(), $this->dogs->findAllForUser($user)), true)) {
                return $this->json(['error' => $this->translator->trans('Invalid quiz result.')], 400);
            }
            if ($dogId === null && !$asNew && ($same = $this->dogs->findByName($user, (string) ($data['quiz_dog_name'] ?? '')))
                && !(($data['quiz_result_id'] ?? '') !== '' && $same->get('result_id') === $data['quiz_result_id'])) {
                return $this->json(['status' => 'choose', 'dog' => ['id' => $same->getId(), 'name' => $same->getName()]]);
            }
            $dog = $this->dogs->saveQuizResult($user, $data, $locale, $dogId, $asNew, $answers);
            $this->em->flush();
            // Members skip the result page: the new score arrives as a banner on the dashboard (proposal 5).
            $history = $dog->getScoreHistory();
            $before = \count($history) > 1 ? $history[\count($history) - 2]['score'] : null;
            $this->addFlash('success', $before === null
                ? $this->translator->trans('%dog%’s Bowl Score: %score%/100. Your plan is below.', ['%dog%' => $dog->getName(), '%score%' => $dog->getBowlScore()])
                : $this->translator->trans('%dog%’s new Bowl Score: %score%/100 (was %before%). Your plan is below.', ['%dog%' => $dog->getName(), '%score%' => $dog->getBowlScore(), '%before%' => $before]));
            // Started from "New chat": straight on to this pet's chat.
            if (\is_array($body) && ($body['then'] ?? null) === 'chat') {
                return $this->json(['status' => 'saved', 'dashboard' => $this->generateUrl('app_chat', ['pet' => $dog->getId()]), 'go' => true, 'dog_id' => $dog->getId()]);
            }
            return $this->json(['status' => 'saved', 'dashboard' => $this->generateUrl('app_dashboard'), 'dog_id' => $dog->getId()]);
        }

        if (mb_strlen($email) > 180 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['error' => $this->translator->trans('Invalid email address.')], 400);
        }
        $ipHash = hash('sha256', ($request->getClientIp() ?? '') . $this->secret);
        $hourAgo = new \DateTimeImmutable('-1 hour');
        if ($this->submissions->countSince('email', $email, $hourAgo) >= self::PER_EMAIL_PER_HOUR
            || $this->submissions->countSince('ipHash', $ipHash, $hourAgo) >= self::PER_IP_PER_HOUR) {
            return $this->json(['error' => $this->translator->trans('Too many tries. Please wait a while.')], 429);
        }

        // Housekeeping: unconfirmed results are only kept for their link's 7 days.
        $this->submissions->deleteExpired();

        $token = bin2hex(random_bytes(32));
        // Where this lead came from, kept with it until the account is made (see Attribution).
        $source = $this->attribution->get($request);
        $extra = ($source ? ['_attribution' => $source] : []) + ($answers !== null && \strlen((string) json_encode($answers)) <= 12000 ? ['_answers' => $answers] : []);
        $submission = new QuizSubmission($email, $firstName !== '' ? $firstName : null, $locale, $data + $extra, $token, $ipHash);
        $this->em->persist($submission);
        $this->em->flush();
        $this->funnel->record('quiz_submit');

        $link = $this->generateUrl('app_quiz_confirm', ['token' => $token], 0);
        $inKlaviyo = $this->klaviyo->setProperties($email, $locale, ['dashboard_link' => $link]);
        // The quiz flow's own email (with dashboard_link) is in English, so Ukrainian and Polish
        // quiz-takers (on the main account's list too) always get the site's translated email.
        if ($this->siteConfirmEmail || !$inKlaviyo || \in_array($locale, ['uk', 'pl'], true)) {
            try {
                $this->emails->sendQuizConfirmation($submission, $link);
            } catch (\Throwable $e) {
                $this->logger->error('Quiz confirmation email failed: {message}', ['message' => $e->getMessage()]);
                if (!$inKlaviyo) {
                    return $this->json(['error' => $this->translator->trans('We could not send the email.')], 503);
                }
            }
        }
        // With the free trial on, the result page's "Start free trial" carries this result to Stripe.
        return $this->json(['status' => 'sent'] + ($this->subscriptions->isEnabled() ? ['trial' => $token] : []));
    }

    #[Route('/quiz/confirm/{token}', name: 'app_quiz_confirm', methods: ['GET'], requirements: ['token' => '[a-f0-9]{64}'])]
    public function confirm(string $token, Request $request, Security $security, UserPasswordHasherInterface $hasher): Response
    {
        $submission = $this->submissions->findByToken($token);
        if (!$submission || $submission->isExpired()) {
            return $this->render('quiz/confirm_failed.html.twig', ['reason' => $submission ? 'expired' : 'invalid'], new Response('', 410));
        }
        if ($submission->getConfirmedAt() !== null) {
            $this->addFlash('info', $this->translator->trans('Your email is already confirmed. Log in to see your dog’s dashboard.'));
            return $this->redirectToRoute($this->getUser() ? 'app_dashboard' : 'app_login');
        }

        [$user, $isNew] = $this->accounts->createFrom($submission);

        $security->login($user, LoginFormAuthenticator::class, 'main');
        $request->setLocale($user->getLocale());
        $this->addFlash('success', $this->translator->trans(
            $isNew ? 'Welcome to ZENOO! Your account is ready and %dog%’s profile is saved.' : '%dog%’s profile is saved to your account.',
            ['%dog%' => $submission->getDogName()],
            null,
            $user->getLocale(),
        ));
        return $this->redirectToRoute('app_dashboard');
    }
}
