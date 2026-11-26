<?php

namespace App\Payment;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The ZENOO app subscription: the quiz and the Bowl Score are free; recommendations, the plan, the
 * coach and the lessons open with a month's free trial (card taken at Stripe Checkout), then a
 * monthly price.
 *
 * The paywall is on only when Stripe is ready (key and webhook secret) and a price is set
 * (SUBSCRIPTION_PRICE_ID, a Stripe Price, or SUBSCRIPTION_PRICE_EUR for an inline monthly price),
 * so a half-configured site never locks people out with no way to pay. PAYWALL=off switches it off.
 */
final class Subscriptions
{
    public const TRIAL_DAYS = 30;

    public function __construct(
        private readonly StripeClient $stripe,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly UrlGeneratorInterface $urls,
        #[Autowire('%env(default::SUBSCRIPTION_PRICE_ID)%')] private readonly ?string $priceId,
        #[Autowire('%env(default::SUBSCRIPTION_PRICE_EUR)%')] private readonly ?string $priceEur,
        #[Autowire('%env(default::PAYWALL)%')] private readonly ?string $paywall,
        #[Autowire('%env(default::TRIAL_BADGE_UNTIL)%')] private readonly ?string $badgeUntil,
    ) {
    }

    public function isEnabled(): bool
    {
        return strtolower(trim((string) $this->paywall)) !== 'off'
            && $this->stripe->isReady()
            && ($this->priceId !== null && trim($this->priceId) !== '' || $this->monthlyPrice() !== null);
    }

    /** Monthly price in euros for the page ("then €9.99 a month"), when set as a number. */
    public function monthlyPrice(): ?float
    {
        $v = str_replace(',', '.', trim((string) $this->priceEur));
        return is_numeric($v) && (float) $v > 0 ? round((float) $v, 2) : null;
    }

    /** Whether this person may use the app (admins, and everyone while the paywall is off). */
    public function hasAccess(?User $user, bool $isAdmin = false): bool
    {
        return $isAdmin || !$this->isEnabled() || ($user !== null && $user->hasActiveSubscription());
    }

    /** The "Cyber Monday Special" badge on the trial, until the end of TRIAL_BADGE_UNTIL (default 7 Dec 2026). */
    public function showBadge(\DateTimeImmutable $now = new \DateTimeImmutable()): bool
    {
        $until = \DateTimeImmutable::createFromFormat('!Y-m-d', trim((string) $this->badgeUntil) ?: '2026-12-07', new \DateTimeZone('Europe/Vilnius'));
        return $until !== false && $now < $until->modify('+1 day');
    }

    /**
     * A Stripe Checkout page for the trial. For a signed-in member $user is set; for someone fresh
     * from the quiz, $submissionToken (their quiz result) and $email, and the account is made when
     * they come back (TrialController::done).
     */
    public function checkoutUrl(?User $user, ?string $submissionToken, ?string $email, string $locale, string $cancelUrl): string
    {
        $item = $this->priceId !== null && trim($this->priceId) !== ''
            ? ['price' => trim($this->priceId), 'quantity' => 1]
            : ['quantity' => 1, 'price_data' => [
                'currency' => 'eur',
                'unit_amount' => (int) round(($this->monthlyPrice() ?? 0) * 100),
                'recurring' => ['interval' => 'month'],
                'product_data' => ['name' => 'ZENOO app'],
            ]];
        $metadata = array_filter(['user_id' => $user?->getId(), 'quiz' => $submissionToken, 'locale' => $locale], fn ($v) => $v !== null && $v !== '');
        $params = [
            'mode' => 'subscription',
            'line_items' => [$item],
            'subscription_data' => ['trial_period_days' => self::TRIAL_DAYS, 'metadata' => $metadata],
            'payment_method_collection' => 'always',
            'metadata' => $metadata,
            'locale' => \in_array($locale, ['lt', 'pl', 'en'], true) ? $locale : 'auto',
            'allow_promotion_codes' => 'true',
            'success_url' => $this->urls->generate('app_trial_done', [], UrlGeneratorInterface::ABSOLUTE_URL) . '?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $cancelUrl,
        ];
        if ($user?->getStripeCustomerId()) {
            $params['customer'] = $user->getStripeCustomerId();
        } elseif ($user || $email) {
            $params['customer_email'] = $user ? $user->getEmail() : $email;
        }
        $session = $this->stripe->createCheckoutSession($params, 'trial-' . sha1(json_encode($metadata) . date('Y-m-d-H')));

        return (string) $session['url'];
    }

    /** Copies a Stripe subscription (from Checkout, a webhook or a fetch) onto its account. */
    public function apply(array $subscription, ?User $user = null): ?User
    {
        $user ??= $this->users->findOneBy(['subscriptionId' => $subscription['id'] ?? ''])
            ?? (($c = $subscription['customer'] ?? null) && \is_string($c) ? $this->users->findOneBy(['stripeCustomerId' => $c]) : null)
            ?? (($id = $subscription['metadata']['user_id'] ?? null) ? $this->users->find((int) $id) : null);
        if (!$user) {
            return null;
        }
        $end = $subscription['trial_end'] ?? null;
        $periodEnd = $subscription['current_period_end'] ?? $subscription['items']['data'][0]['current_period_end'] ?? null;
        $until = max((int) $end, (int) $periodEnd);
        $user->setSubscription(
            (string) ($subscription['status'] ?? 'incomplete'),
            (string) ($subscription['id'] ?? '') ?: null,
            \is_string($subscription['customer'] ?? null) ? $subscription['customer'] : ($subscription['customer']['id'] ?? null),
            $until > 0 ? (new \DateTimeImmutable())->setTimestamp($until) : null,
        );
        $this->em->flush();

        return $user;
    }

    /** A Stripe customer portal page (change card, cancel). */
    public function portalUrl(User $user, string $returnUrl): ?string
    {
        if (!$user->getStripeCustomerId()) {
            return null;
        }
        return (string) ($this->stripe->createPortalSession($user->getStripeCustomerId(), $returnUrl)['url'] ?? '') ?: null;
    }

    public function stripe(): StripeClient
    {
        return $this->stripe;
    }
}
