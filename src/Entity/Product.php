<?php

namespace App\Entity;

use App\Repository\ProductRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/** Something a pet parent can buy once through Stripe Checkout. */
#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_PRODUCT_SLUG', fields: ['slug'])]
#[UniqueEntity(fields: ['slug'])]
class Product
{
    /** Stripe's minimum charge for EUR is €0.50. */
    public const MIN_CENTS = 50;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank]
    private string $name = '';

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', message: 'Use lowercase letters, numbers and hyphens only.')]
    private string $slug = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $tagline = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /** Price in euros, e.g. "1.00". */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Assert\NotBlank]
    #[Assert\GreaterThanOrEqual(value: 0.5, message: 'Stripe needs a price of at least €0.50.')]
    private string $price = '0.00';

    /** File name in public/images/, e.g. "intro-hero.jpg". */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $image = null;

    /** Inactive products are hidden from the shop and can't be bought. */
    #[ORM\Column]
    private bool $active = true;

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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = trim($name);

        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = trim($slug);

        return $this;
    }

    public function getTagline(): ?string
    {
        return $this->tagline;
    }

    public function setTagline(?string $tagline): static
    {
        $this->tagline = $tagline;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getPrice(): string
    {
        return number_format((float) $this->price, 2, '.', '');
    }

    public function setPrice(string $price): static
    {
        $this->price = number_format((float) $price, 2, '.', '');

        return $this;
    }

    public function getPriceCents(): int
    {
        return (int) round(((float) $this->price) * 100);
    }

    public function getImage(): ?string
    {
        return $this->image;
    }

    public function setImage(?string $image): static
    {
        $this->image = $image;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
