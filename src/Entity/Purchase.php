<?php

namespace App\Entity;

use App\Repository\PurchaseRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A paid Stripe Checkout Session. Created only once Stripe says the session is paid,
 * by the webhook or the return page, whichever arrives first. The unique session id
 * makes sure the same payment can never be recorded twice.
 */
#[ORM\Entity(repositoryClass: PurchaseRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_PURCHASE_STRIPE_SESSION', fields: ['stripeSessionId'])]
#[ORM\Index(name: 'IDX_PURCHASE_PAYMENT_INTENT', fields: ['stripePaymentIntentId'])]
class Purchase
{
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_REFUNDED = 'refunded';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'purchases')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Product $product = null;

    /** What was actually charged, after any discount code. */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $amount = '0.00';

    #[ORM\Column(length: 3)]
    private string $currency = 'eur';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $discountAmount = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_COMPLETED;

    #[ORM\Column(length: 255)]
    private string $stripeSessionId = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripePaymentIntentId = null;

    /** True when Stripe was in test mode (sk_test_ key): no real money moved. */
    #[ORM\Column]
    private bool $testMode = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getProduct(): ?Product
    {
        return $this->product;
    }

    public function setProduct(?Product $product): static
    {
        $this->product = $product;

        return $this;
    }

    public function getAmount(): string
    {
        return number_format((float) $this->amount, 2, '.', '');
    }

    public function setAmount(string $amount): static
    {
        $this->amount = number_format((float) $amount, 2, '.', '');

        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): static
    {
        $this->currency = mb_strtolower($currency);

        return $this;
    }

    public function getDiscountAmount(): ?string
    {
        return $this->discountAmount !== null ? number_format((float) $this->discountAmount, 2, '.', '') : null;
    }

    public function setDiscountAmount(?string $discountAmount): static
    {
        $this->discountAmount = $discountAmount;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function getStripeSessionId(): string
    {
        return $this->stripeSessionId;
    }

    public function setStripeSessionId(string $stripeSessionId): static
    {
        $this->stripeSessionId = $stripeSessionId;

        return $this;
    }

    public function getStripePaymentIntentId(): ?string
    {
        return $this->stripePaymentIntentId;
    }

    public function setStripePaymentIntentId(?string $id): static
    {
        $this->stripePaymentIntentId = $id;

        return $this;
    }

    public function isTestMode(): bool
    {
        return $this->testMode;
    }

    public function setTestMode(bool $testMode): static
    {
        $this->testMode = $testMode;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function __toString(): string
    {
        return sprintf('#%d %s', (int) $this->id, $this->product?->getName() ?? '');
    }
}
