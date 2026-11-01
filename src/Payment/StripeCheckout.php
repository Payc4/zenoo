<?php

namespace App\Payment;

use App\Entity\Product;
use App\Entity\Purchase;
use App\Entity\User;
use App\Repository\ProductRepository;
use App\Repository\PurchaseRepository;
use App\Repository\UserRepository;
use App\Service\EmailService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

/**
 * One-off product purchases through Stripe Checkout.
 *
 * The buyer is always signed in, so every paid session belongs to a known account.
 * Fulfilment runs from the webhook and from the return page, whichever comes first;
 * the unique stripe_session_id column makes it safe to run twice.
 */
class StripeCheckout
{
    public const CURRENCY = 'eur';

    public function __construct(
        private readonly StripeClient $stripe,
        private readonly EntityManagerInterface $em,
        private readonly ManagerRegistry $doctrine,
        private readonly PurchaseRepository $purchases,
        private readonly UserRepository $users,
        private readonly ProductRepository $products,
        private readonly EmailService $emails,
        private readonly LoggerInterface $logger,
        private readonly string $appUrl,
    ) {
    }

    /** Creates the Checkout Session and returns the URL to send the buyer to. */
    public function start(Product $product, User $user): string
    {
        // Same user + product within the same minute → the same session (double clicks).
        $idempotencyKey = sprintf('checkout-%d-%d-%s', $user->getId(), $product->getId(), date('YmdHi'));
        $session = $this->stripe->createCheckoutSession(self::sessionParams($product, $user, $this->appUrl), $idempotencyKey);
        if (empty($session['url'])) {
            throw new StripeException('Stripe did not return a checkout URL.');
        }

        return $session['url'];
    }

    /** @return array<string, mixed> Checkout Session parameters for one product */
    public static function sessionParams(Product $product, User $user, string $appUrl): array
    {
        $base = rtrim($appUrl, '/');
        $cents = $product->getPriceCents();
        if ($cents < Product::MIN_CENTS) {
            throw new StripeException('This product has no valid price set.');
        }

        $productData = ['name' => $product->getName()];
        if ($product->getTagline()) {
            $productData['description'] = $product->getTagline();
        }
        // Stripe fetches the image itself, so only send it when the site is public over https.
        if ($product->getImage() && str_starts_with($base, 'https://')) {
            $productData['images'] = [$base . '/images/' . rawurlencode($product->getImage())];
        }

        $metadata = ['product_id' => (string) $product->getId(), 'user_id' => (string) $user->getId()];

        return [
            'mode' => 'payment',
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => self::CURRENCY,
                    'unit_amount' => $cents,
                    'product_data' => $productData,
                ],
            ]],
            'customer_email' => $user->getEmail(),
            'client_reference_id' => (string) $user->getId(),
            // Discount codes are created in the Stripe dashboard and typed in on Stripe's page.
            'allow_promotion_codes' => true,
            'success_url' => $base . '/shop/return?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $base . '/shop/' . rawurlencode($product->getSlug()) . '?cancelled=1',
            'metadata' => $metadata,
            'payment_intent_data' => ['metadata' => $metadata],
        ];
    }

    public static function isPaid(array $session): bool
    {
        return ($session['mode'] ?? '') === 'payment'
            && in_array($session['payment_status'] ?? '', ['paid', 'no_payment_required'], true);
    }

    /**
     * Records the purchase for a paid Checkout Session. Safe to call more than once.
     *
     * @return Purchase|null null when the session isn't paid or doesn't match a user and product
     */
    public function fulfil(array $session): ?Purchase
    {
        $sessionId = (string) ($session['id'] ?? '');
        if ($sessionId === '' || !self::isPaid($session)) {
            return null;
        }

        $existing = $this->purchases->findOneBy(['stripeSessionId' => $sessionId]);
        if ($existing) {
            return $existing;
        }

        $user = $this->users->find((int) ($session['metadata']['user_id'] ?? $session['client_reference_id'] ?? 0));
        $product = $this->products->find((int) ($session['metadata']['product_id'] ?? 0));
        if (!$user || !$product) {
            $this->logger->error('Paid Stripe session does not match a user and product', ['session' => $sessionId, 'metadata' => $session['metadata'] ?? null]);

            return null;
        }

        $paymentIntent = is_array($session['payment_intent'] ?? null) ? ($session['payment_intent']['id'] ?? null) : ($session['payment_intent'] ?? null);
        $purchase = (new Purchase())
            ->setProduct($product)
            ->setAmount(number_format(((int) ($session['amount_total'] ?? 0)) / 100, 2, '.', ''))
            ->setCurrency((string) ($session['currency'] ?? self::CURRENCY))
            ->setStatus(Purchase::STATUS_COMPLETED)
            ->setStripeSessionId($sessionId)
            ->setStripePaymentIntentId($paymentIntent ?: null)
            ->setTestMode(isset($session['livemode']) ? !$session['livemode'] : $this->stripe->isTestMode());
        $discount = (int) ($session['total_details']['amount_discount'] ?? 0);
        if ($discount > 0) {
            $purchase->setDiscountAmount(number_format($discount / 100, 2, '.', ''));
        }
        $user->addPurchase($purchase);

        try {
            $this->em->persist($purchase);
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // The webhook and the return page arrived at the same moment and the other one won.
            // The EntityManager is closed after a failed flush, so look it up with a fresh one.
            $this->doctrine->resetManager();

            return $this->doctrine->getRepository(Purchase::class)->findOneBy(['stripeSessionId' => $sessionId]);
        }

        $this->logger->info('Stripe purchase recorded', ['purchase' => $purchase->getId(), 'session' => $sessionId]);
        try {
            $this->emails->sendPurchaseConfirmation($purchase);
        } catch (\Throwable $e) {
            $this->logger->error('Purchase email failed', ['purchase' => $purchase->getId(), 'exception' => $e]);
        }

        return $purchase;
    }

    /**
     * A full refund (from the Stripe dashboard) marks the purchase refunded. Partial refunds keep it.
     *
     * @return int number of purchases marked refunded
     */
    public function refund(array $charge): int
    {
        $paymentIntent = (string) ($charge['payment_intent'] ?? '');
        if ($paymentIntent === '' || empty($charge['refunded'])) {
            return 0;
        }

        $n = 0;
        foreach ($this->purchases->findBy(['stripePaymentIntentId' => $paymentIntent]) as $purchase) {
            if ($purchase->getStatus() !== Purchase::STATUS_REFUNDED) {
                $purchase->setStatus(Purchase::STATUS_REFUNDED);
                $n++;
            }
        }
        if ($n) {
            $this->em->flush();
            $this->logger->info('Stripe refund recorded', ['payment_intent' => $paymentIntent, 'purchases' => $n]);
        }

        return $n;
    }
}
