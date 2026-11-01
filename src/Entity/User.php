<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\UniqueConstraint(name: 'UNIQ_USER_EMAIL', fields: ['email'])]
#[UniqueEntity(fields: ['email'], message: 'An account with this email already exists.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank(message: 'Please enter your email address.')]
    #[Assert\Email(message: 'Please enter a valid email address.')]
    private ?string $email = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $firstName = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $lastName = null;

    /** @var list<string> */
    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $profileImage = null;

    /** sha256 of the emailed reset token; the token itself is never stored. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $resetTokenHash = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $resetTokenExpiresAt = null;

    /** Part of the login-link signature: updating it invalidates every link sent before. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastMagicLinkRequestedAt = null;

    /** @var Collection<int, Purchase> */
    #[ORM\OneToMany(mappedBy: 'user', targetEntity: Purchase::class)]
    #[ORM\OrderBy(['createdAt' => 'DESC'])]
    private Collection $purchases;

    /** Only used by the admin form when creating or changing a password. */
    private ?string $plainPassword = null;

    public function __construct()
    {
        $this->purchases = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = mb_strtolower(trim($email));

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): static
    {
        $this->roles = array_values(array_unique($roles));

        return $this;
    }

    public function isAdmin(): bool
    {
        return in_array('ROLE_ADMIN', $this->roles, true);
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    /** Nothing sensitive is kept on the object (plainPassword is cleared where it's used). */
    #[\Deprecated]
    public function eraseCredentials(): void
    {
    }

    public function getPlainPassword(): ?string
    {
        return $this->plainPassword;
    }

    public function setPlainPassword(?string $plainPassword): static
    {
        $this->plainPassword = $plainPassword;

        return $this;
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function setFirstName(?string $firstName): static
    {
        $this->firstName = $firstName !== null ? trim($firstName) : null;

        return $this;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function setLastName(?string $lastName): static
    {
        $this->lastName = $lastName !== null ? trim($lastName) : null;

        return $this;
    }

    public function getFullName(): string
    {
        return trim(($this->firstName ?? '') . ' ' . ($this->lastName ?? ''));
    }

    /** First name for greetings, falling back to the part of the email before the @. */
    public function getDisplayName(): string
    {
        return $this->firstName ?: ucfirst(explode('@', (string) $this->email)[0]);
    }

    public function getInitials(): string
    {
        $name = $this->getFullName() ?: (string) $this->email;
        $parts = preg_split('/\s+/', $name) ?: [];

        return mb_strtoupper(implode('', array_map(fn (string $p) => mb_substr($p, 0, 1), array_slice($parts, 0, 2))));
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

    public function getLastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function setLastLoginAt(?\DateTimeImmutable $lastLoginAt): static
    {
        $this->lastLoginAt = $lastLoginAt;

        return $this;
    }

    public function getProfileImage(): ?string
    {
        return $this->profileImage;
    }

    public function setProfileImage(?string $profileImage): static
    {
        $this->profileImage = $profileImage;

        return $this;
    }

    public function getResetTokenHash(): ?string
    {
        return $this->resetTokenHash;
    }

    public function getResetTokenExpiresAt(): ?\DateTimeImmutable
    {
        return $this->resetTokenExpiresAt;
    }

    /** Stores only a hash of $token, so a leaked database can't be used to reset passwords. */
    public function setResetToken(?string $token, ?\DateTimeImmutable $expiresAt = null): static
    {
        $this->resetTokenHash = $token !== null ? hash('sha256', $token) : null;
        $this->resetTokenExpiresAt = $token !== null ? $expiresAt : null;

        return $this;
    }

    public function getLastMagicLinkRequestedAt(): ?\DateTimeImmutable
    {
        return $this->lastMagicLinkRequestedAt;
    }

    public function setLastMagicLinkRequestedAt(?\DateTimeImmutable $at): static
    {
        $this->lastMagicLinkRequestedAt = $at;

        return $this;
    }

    /** @return Collection<int, Purchase> */
    public function getPurchases(): Collection
    {
        return $this->purchases;
    }

    /** @return list<Purchase> */
    public function getCompletedPurchases(): array
    {
        return array_values($this->purchases->filter(fn (Purchase $p) => $p->isCompleted())->toArray());
    }

    public function addPurchase(Purchase $purchase): static
    {
        if (!$this->purchases->contains($purchase)) {
            $this->purchases->add($purchase);
            $purchase->setUser($this);
        }

        return $this;
    }

    public function hasPurchased(Product $product): bool
    {
        foreach ($this->purchases as $purchase) {
            if ($purchase->isCompleted() && $purchase->getProduct() === $product) {
                return true;
            }
        }

        return false;
    }

    public function __toString(): string
    {
        return (string) $this->email;
    }
}
