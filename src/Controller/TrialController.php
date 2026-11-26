<?php

namespace App\Controller;

use App\Entity\User;
use App\Payment\StripeException;
use App\Payment\Subscriptions;
use App\Quiz\QuizAccounts;
use App\Repository\DogProfileRepository;
use App\Repository\QuizSubmissionRepository;
use App\Security\LoginFormAuthenticator;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The free trial: the quiz and the Bowl Score are free; the recommendations, plan, coach and lessons
 * open with a month's free trial, card taken at Stripe Checkout (Subscriptions).
 *
 *  - /trial          the paywall page (signed-in members without a trial or plan land here)
 *  - /trial/start    to Stripe Checkout: for a member, or for someone fresh from the quiz (its token)
 *  - /trial/done     back from Stripe: the account is made from the quiz if needed, signed in, open
 *  - /account/billing  Stripe's customer portal (change card, cancel)
 */
final class TrialController extends AbstractController
{
    public function __construct(
        private readonly Subscriptions $subscriptions,
        private readonly DogProfileRepository $dogs,
        private readonly QuizSubmissionRepository $submissions,
        private readonly QuizAccounts $accounts,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/trial', name: 'app_trial', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }
        if ($this->subscriptions->hasAccess($user, $this->isGranted('ROLE_ADMIN'))) {
            return $this->redirectToRoute('app_dashboard');
        }

        return $this->render('trial/index.html.twig', [
            'dog' => $this->dogs->findForUser($user),
            'price' => $this->subscriptions->monthlyPrice(),
            'badge' => $this->subscriptions->showBadge(),
            'days' => Subscriptions::TRIAL_DAYS,
            'lapsed' => $user->getSubscriptionStatus() !== null,
        ]);
    }

    #[Route('/trial/start', name: 'app_trial_start', methods: ['POST'])]
    public function start(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('trial', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->subscriptions->isEnabled()) {
            return $this->redirectToRoute('app_dashboard');
        }
        $user = $this->getUser();
        $user = $user instanceof User ? $user : null;
        $submission = null;
        if ($user === null) {
            $token = (string) $request->request->get('quiz');
            $submission = preg_match('/^[a-f0-9]{64}$/', $token) ? $this->submissions->findByToken($token) : null;
            if (!$submission || $submission->isExpired()) {
                return $this->redirectToRoute('app_login');
            }
            // An account with this email exists: its owner logs in first. Otherwise anyone could type
            // someone else's email in the quiz, pay with their own card and land in that account.
            if ($this->accounts->existing($submission)) {
                $this->addFlash('info', 'You already have a ZENOO account. Log in to start your free trial.');
                return $this->redirectToRoute('app_login');
            }
        }
        $back = $user ? $this->generateUrl('app_trial', [], 0) : $this->generateUrl('app_quiz', [], 0);
        try {
            $url = $this->subscriptions->checkoutUrl($user, $submission ? (string) $request->request->get('quiz') : null, $submission?->getEmail(), $request->getLocale(), $back);
        } catch (StripeException $e) {
            $this->logger->error('Trial checkout failed', ['exception' => $e]);
            $this->addFlash('error', 'We couldn’t open the secure checkout. Please try again in a moment.');
            return $user ? $this->redirectToRoute('app_trial') : $this->redirectToRoute('app_quiz');
        }

        return new RedirectResponse($url, 303);
    }

    #[Route('/trial/done', name: 'app_trial_done', methods: ['GET'])]
    public function done(Request $request, Security $security): Response
    {
        try {
            $session = $this->subscriptions->stripe()->retrieveCheckoutSession((string) $request->query->get('session_id'));
        } catch (StripeException) {
            return $this->redirectToRoute($this->getUser() ? 'app_trial' : 'app_quiz');
        }
        if (($session['mode'] ?? '') !== 'subscription' || ($session['status'] ?? '') !== 'complete') {
            return $this->redirectToRoute($this->getUser() ? 'app_trial' : 'app_quiz');
        }

        [$user, $mayLogIn] = $this->accountFor($session);
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }
        if (\is_string($session['subscription'] ?? null)) {
            $this->subscriptions->apply($this->subscriptions->stripe()->retrieveSubscription($session['subscription']), $user);
        }
        if ($this->getUser() !== $user) {
            if (!$mayLogIn) {
                $this->addFlash('success', $this->translator->trans('Your free month has started. Log in to open everything.', [], null, $user->getLocale()));
                return $this->redirectToRoute('app_login');
            }
            $security->login($user, LoginFormAuthenticator::class, 'main');
        }
        $request->setLocale($user->getLocale());
        $this->addFlash('success', $this->translator->trans('Your free month has started. Everything is open now.', [], null, $user->getLocale()));

        return $this->redirectToRoute('app_dashboard');
    }

    #[Route('/account/billing', name: 'app_billing', methods: ['POST'])]
    public function billing(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$this->isCsrfTokenValid('billing', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        try {
            $url = $this->subscriptions->portalUrl($user, $this->generateUrl('app_account', [], 0));
        } catch (StripeException $e) {
            $this->logger->error('Billing portal failed', ['exception' => $e]);
            $url = null;
        }
        if (!$url) {
            $this->addFlash('error', 'We couldn’t open the billing page. Please try again in a moment.');
            return $this->redirectToRoute('app_account');
        }

        return new RedirectResponse($url, 303);
    }

    /**
     * The account a finished trial checkout belongs to, and whether this browser may be signed in to
     * it: the member who started it, or a new account made from their quiz (never one that already
     * existed: its owner logs in themselves).
     *
     * @return array{?User, bool}
     */
    private function accountFor(array $session): array
    {
        $meta = (array) ($session['metadata'] ?? []);
        $current = $this->getUser();
        if (!empty($meta['user_id'])) {
            return $current instanceof User && $current->getId() === (int) $meta['user_id'] ? [$current, true] : [null, false];
        }
        $submission = !empty($meta['quiz']) ? $this->submissions->findByToken((string) $meta['quiz']) : null;
        if (!$submission) {
            return [null, false];
        }
        $existing = $this->accounts->existing($submission);
        if ($existing) {
            // Made from this quiz just now by the webhook (new, never logged in): fine to sign in.
            $madeNow = $submission->getConfirmedAt() !== null && $existing->getCreatedAt() >= $submission->getCreatedAt() && $existing->getLastLoginAt() === null;
            return [$existing, $madeNow];
        }

        return [$this->accounts->createFrom($submission)[0], true];
    }
}
