<?php

namespace App\Entity;

use App\Repository\QuizSubmissionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A finished quiz from someone who isn't logged in, waiting for them to click the link in
 * the "confirm your email" message. Clicking it creates (or finds) their account and saves
 * the dog profile. Only a hash of the link's token is stored.
 */
#[ORM\Entity(repositoryClass: QuizSubmissionRepository::class)]
#[ORM\Index(name: 'IDX_QUIZ_SUBMISSION_EMAIL', columns: ['email'])]
#[ORM\Index(name: 'IDX_QUIZ_SUBMISSION_IP', columns: ['ip_hash'])]
class QuizSubmission
{
    public const LIFETIME = '+7 days';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private string $email;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $firstName = null;

    #[ORM\Column(length: 5)]
    private string $locale = 'en';

    #[ORM\Column(type: 'json')]
    private array $data = [];

    #[ORM\Column(length: 64, unique: true)]
    private string $tokenHash;

    #[ORM\Column(length: 64)]
    private string $ipHash;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $confirmedAt = null;

    public function __construct(string $email, ?string $firstName, string $locale, array $data, string $token, string $ipHash)
    {
        $this->email = mb_strtolower(trim($email));
        $this->firstName = $firstName !== null && trim($firstName) !== '' ? mb_substr(trim($firstName), 0, 100) : null;
        $this->locale = $locale;
        $this->data = $data;
        $this->tokenHash = hash('sha256', $token);
        $this->ipHash = $ipHash;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getEmail(): string { return $this->email; }
    public function getFirstName(): ?string { return $this->firstName; }
    public function getLocale(): string { return $this->locale; }
    public function getData(): array { return $this->data; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getConfirmedAt(): ?\DateTimeImmutable { return $this->confirmedAt; }

    public function getDogName(): string
    {
        return trim((string) ($this->data['quiz_dog_name'] ?? '')) ?: 'your dog';
    }

    public function isExpired(): bool
    {
        return $this->createdAt->modify(self::LIFETIME) < new \DateTimeImmutable();
    }

    public function confirm(): void
    {
        $this->confirmedAt = new \DateTimeImmutable();
    }
}
