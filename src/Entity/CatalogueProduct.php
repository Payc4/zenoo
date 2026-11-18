<?php

namespace App\Entity;

use App\Repository\CatalogueProductRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A product from the main ZENOO store (Shopify), copied by app:catalogue:sync. What the sync reads
 * (title, prices, pack sizes, kcal and analysis parsed from the description) is kept apart from
 * what an admin sets in Admin → Catalogue (kind, species, kcal, hidden, notes), so a re-sync never
 * overwrites a correction. The personal plan and the coach only use products that are active,
 * not hidden and have an energy value.
 */
#[ORM\Entity(repositoryClass: CatalogueProductRepository::class)]
#[ORM\Table(name: 'catalogue_product')]
class CatalogueProduct
{
    public const KINDS = ['complete' => 'Complete food', 'topper' => 'Topper / complementary food', 'treat' => 'Treat or chew', 'supplement' => 'Supplement', 'other' => 'Other'];
    public const SPECIES = ['dog' => 'Dogs', 'cat' => 'Cats', 'both' => 'Dogs and cats'];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'bigint', unique: true)]
    private string $shopifyId;

    #[ORM\Column(length: 255)]
    private string $handle = '';

    #[ORM\Column(length: 255)]
    private string $title = '';

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $productType = null;

    #[ORM\Column(type: 'json')]
    private array $tags = [];

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $image = null;

    /** [{id, title, price, packGrams, available}] */
    #[ORM\Column(type: 'json')]
    private array $variants = [];

    /** The description as plain text (for the coach), at most 4,000 characters. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /** Parsed from the description by the sync. */
    #[ORM\Column(nullable: true)]
    private ?float $kcalParsed = null;

    /** {protein, fat, fibre, ash, moisture} in % as fed, parsed from the description. */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $analysis = null;

    #[ORM\Column(length: 20)]
    private string $kindGuess = 'other';

    #[ORM\Column(length: 10)]
    private string $speciesGuess = 'dog';

    // ---- Set by an admin; win over what the sync read ----
    #[ORM\Column(nullable: true)]
    private ?float $kcalOverride = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $kindOverride = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $speciesOverride = null;

    #[ORM\Column]
    private bool $hidden = false;

    /** For the coach, e.g. "rich and high in fat: not for dogs on a low-fat diet". */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $notes = null;

    /** Still in the store at the last sync. */
    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column]
    private \DateTimeImmutable $syncedAt;

    public function __construct(string $shopifyId)
    {
        $this->shopifyId = $shopifyId;
        $this->syncedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getShopifyId(): string { return $this->shopifyId; }
    public function getHandle(): string { return $this->handle; }
    public function getTitle(): string { return $this->title; }
    public function getProductType(): ?string { return $this->productType; }
    public function getTags(): array { return $this->tags; }
    public function getImage(): ?string { return $this->image; }
    public function getVariants(): array { return $this->variants; }
    public function getDescription(): ?string { return $this->description; }
    public function getAnalysis(): ?array { return $this->analysis; }
    public function getKcalParsed(): ?float { return $this->kcalParsed; }
    public function getKcalOverride(): ?float { return $this->kcalOverride; }
    public function getKindOverride(): ?string { return $this->kindOverride; }
    public function getSpeciesOverride(): ?string { return $this->speciesOverride; }
    public function isHidden(): bool { return $this->hidden; }
    public function getNotes(): ?string { return $this->notes; }
    public function isActive(): bool { return $this->active; }
    public function getSyncedAt(): \DateTimeImmutable { return $this->syncedAt; }

    /** kcal per 100 g as fed: the admin's value, else the one read from the description. */
    public function getKcal100(): ?float
    {
        return $this->kcalOverride ?? $this->kcalParsed;
    }

    public function getKind(): string
    {
        return $this->kindOverride ?? $this->kindGuess;
    }

    public function getSpecies(): string
    {
        return $this->speciesOverride ?? $this->speciesGuess;
    }

    public function isForDogs(): bool
    {
        return \in_array($this->getSpecies(), ['dog', 'both'], true);
    }

    /** Usable in plans and by the coach. */
    public function isUsable(): bool
    {
        return $this->active && !$this->hidden && $this->isForDogs() && $this->getKcal100() !== null && $this->cheapestPerGram() !== null;
    }

    /** The available variant with the lowest price per gram: [variant, €/g], or null. */
    public function cheapestPerGram(): ?array
    {
        $best = null;
        foreach ($this->variants as $v) {
            if (!($v['available'] ?? true) || empty($v['packGrams']) || ($v['price'] ?? 0) <= 0) {
                continue;
            }
            $per = $v['price'] / $v['packGrams'];
            if ($best === null || $per < $best[1]) {
                $best = [$v, $per];
            }
        }
        return $best;
    }

    public function applySync(array $data, \DateTimeImmutable $at): void
    {
        $this->handle = $data['handle'];
        $this->title = $data['title'];
        $this->productType = $data['productType'] ?: null;
        $this->tags = $data['tags'];
        $this->image = $data['image'];
        $this->variants = $data['variants'];
        $this->description = $data['description'];
        $this->kcalParsed = $data['kcal'];
        $this->analysis = $data['analysis'];
        $this->kindGuess = $data['kind'];
        $this->speciesGuess = $data['species'];
        $this->active = true;
        $this->syncedAt = $at;
    }

    public function markGone(): void
    {
        $this->active = false;
    }

    public function applyAdmin(?float $kcal, ?string $kind, ?string $species, bool $hidden, ?string $notes): void
    {
        $this->kcalOverride = $kcal !== null && $kcal > 0 ? $kcal : null;
        $this->kindOverride = $kind !== null && isset(self::KINDS[$kind]) ? $kind : null;
        $this->speciesOverride = $species !== null && isset(self::SPECIES[$species]) ? $species : null;
        $this->hidden = $hidden;
        $this->notes = $notes !== null && trim($notes) !== '' ? mb_substr(trim($notes), 0, 500) : null;
    }
}
