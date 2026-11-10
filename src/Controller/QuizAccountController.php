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

        $user = $this->getUser();
        if ($user instanceof User) {
            $this->dogs->saveQuizResult($user, $data, $locale);
            $this->em->flush();
            return $this->json(['status' => 'saved', 'dashboard' => $this->generateUrl('app_dashboard')]);
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
        $submission = new QuizSubmission($email, $firstName !== '' ? $firstName : null, $locale, $data, $token, $ipHash);
        $this->em->persist($submission);
        $this->em->flush();

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
        return $this->json(['status' => 'sent']);
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

        $user = $this->users->findOneBy(['email' => $submission->getEmail()]);
        $isNew = $user === null;
        if ($isNew) {
            $user = (new User())
                ->setEmail($submission->getEmail())
                ->setFirstName($submission->getFirstName())
                ->setLocale($submission->getLocale())
                ->setHasPassword(false);
            // Nobody knows this password: they log in with the email link or choose one on the dashboard.
            $user->setPassword($hasher->hashPassword($user, bin2hex(random_bytes(32))));
            $this->em->persist($user);
        }
        $this->dogs->saveQuizResult($user, $submission->getData(), $submission->getLocale());
        $submission->confirm();
        $this->em->flush();

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
