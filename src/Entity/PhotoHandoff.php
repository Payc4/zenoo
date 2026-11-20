<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * "Send a photo from your phone": the computer shows a QR code with a one-off link, the phone
 * opens it and takes photos, and the computer picks them up (App\Controller\PhotoHandoffController).
 *
 * The link's secret is only stored as a SHA-256 hash. The computer that asked for it is the
 * owner ("u:<user id>" when signed in, else "s:<hash of the session id>"); only it can read the
 * photos. Hand-offs end after TTL_MINUTES, and their photos are deleted with them.
 */
#[ORM\Entity]
#[ORM\Index(name: 'IDX_PHOTO_HANDOFF_EXPIRES', fields: ['expiresAt'])]
#[ORM\UniqueConstraint(name: 'UNIQ_PHOTO_HANDOFF_TOKEN', fields: ['tokenHash'])]
class PhotoHandoff
{
    public const TTL_MINUTES = 15;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $tokenHash;

    #[ORM\Column(length: 80)]
    private string $owner;

    /** How many photos the phone may send (the chat takes 3, a dog photo or a label 1). */
    #[ORM\Column]
    private int $maxFiles;

    /** The phone page's language. */
    #[ORM\Column(length: 5)]
    private string $locale;

    /** @var list<array{file: string, w: int, h: int}> */
    #[ORM\Column(type: 'json')]
    private array $files = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    public function __construct(string $token, string $owner, int $maxFiles, string $locale)
    {
        $this->tokenHash = self::hash($token);
        $this->owner = $owner;
        $this->maxFiles = max(1, min(3, $maxFiles));
        $this->locale = $locale;
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = $this->createdAt->modify('+' . self::TTL_MINUTES . ' minutes');
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): string
    {
        return $this->owner;
    }

    public function getMaxFiles(): int
    {
        return $this->maxFiles;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    /** @return list<array{file: string, w: int, h: int}> */
    public function getFiles(): array
    {
        return $this->files;
    }

    public function addFile(string $file, int $w, int $h): void
    {
        $this->files[] = ['file' => $file, 'w' => $w, 'h' => $h];
    }

    public function isFull(): bool
    {
        return \count($this->files) >= $this->maxFiles;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        return ($now ?? new \DateTimeImmutable()) >= $this->expiresAt;
    }
}
