<?php

namespace App\Controller;

use App\Payment\StripeCheckout;
use App\Payment\StripeClient;
use App\Payment\StripeException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class WebhookController extends AbstractController
{
    /** Events to select in the Stripe dashboard when adding the webhook endpoint. */
    public const STRIPE_EVENTS = [
        'checkout.session.completed',
        'checkout.session.async_payment_succeeded',
        'charge.refunded',
    ];

    public function __construct(
        private readonly StripeClient $stripe,
        private readonly StripeCheckout $checkout,
        private readonly LoggerInterface $logger,
        private readonly \App\Payment\Subscriptions $subscriptions,
        private readonly \App\Quiz\QuizAccounts $accounts,
        private readonly \App\Repository\QuizSubmissionRepository $submissions,
    ) {
    }

    /**
     * Stripe webhook. Every request must carry a valid Stripe-Signature, so nobody can fake
     * a payment by posting to this URL. A 5xx answer makes Stripe retry later.
     */
    #[Route('/webhook/stripe', name: 'app_webhook_stripe', methods: ['POST'])]
    public function stripe(Request $request): JsonResponse
    {
        try {
            $event = $this->stripe->verifyWebhook($request->getContent(), $request->headers->get('Stripe-Signature'));
        } catch (StripeException $e) {
            $this->logger->warning('Rejected Stripe webhook', ['reason' => $e->getMessage()]);

            return new JsonResponse(['error' => $e->getMessage()], $e->getCode() >= 500 ? 500 : 400);
        }

        $type = (string) $event['type'];
        $object = (array) $event['data']['object'];
        try {
            switch ($type) {
                case 'checkout.session.completed':
                case 'checkout.session.async_payment_succeeded':
                    if (($object['mode'] ?? '') === 'subscription') {
                        $this->trialStarted($object);
                        break;
                    }
                    if (($object['mode'] ?? '') !== 'payment' || !StripeCheckout::isPaid($object)) {
                        // Delayed payment methods: wait for async_payment_succeeded.
                        break;
                    }
                    if (!$this->checkout->fulfil($object)) {
                        // Paid but unmatched (e.g. product deleted): keep it visible in Stripe's retry log.
                        return new JsonResponse(['error' => 'Could not record this purchase.'], 500);
                    }
                    break;

                case 'charge.refunded':
                    $this->checkout->refund($object);
                    break;

                // The trial ends, a renewal is paid or fails, the plan is cancelled: keep the account in step.
                case 'customer.subscription.created':
                case 'customer.subscription.updated':
                case 'customer.subscription.deleted':
                case 'customer.subscription.trial_will_end':
                    $this->subscriptions->apply($object);
                    break;
            }
        } catch (\Throwable $e) {
            $this->logger->error('Stripe webhook failed', ['event' => $event['id'] ?? null, 'type' => $type, 'exception' => $e]);

            return new JsonResponse(['error' => 'Processing failed.'], 500);
        }

        return new JsonResponse(['received' => true]);
    }

    /**
     * A trial checkout finished. Normally the person comes back to /trial/done, which does the same;
     * this covers a closed tab: the account is made from their quiz (if it doesn't exist) and the
     * subscription put on it, so they can log in with an email link.
     */
    private function trialStarted(array $session): void
    {
        $meta = (array) ($session['metadata'] ?? []);
        $user = null;
        if (empty($meta['user_id']) && !empty($meta['quiz']) && ($submission = $this->submissions->findByToken((string) $meta['quiz']))) {
            $user = $this->accounts->existing($submission) ?? $this->accounts->createFrom($submission)[0];
        }
        if (\is_string($session['subscription'] ?? null)) {
            $this->subscriptions->apply($this->stripe->retrieveSubscription($session['subscription']), $user);
        }
    }
}
