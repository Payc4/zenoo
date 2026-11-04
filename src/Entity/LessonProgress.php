<?php

namespace App\Entity;

use App\Repository\LessonProgressRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A learner's progress in one lesson: where they are, what they answered
 * (keyed by slide id), and when they finished.
 *
 * answers = {
 *   "s_ab12cd": {"quiz": [{"choice": 2, "correct": true}], "text": ["..."], "voice": "file.webm"}
 * }
 */
#[ORM\Entity(repositoryClass: LessonProgressRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_LESSON_PROGRESS_USER_LESSON', fields: ['user', 'lesson'])]
#[ORM\HasLifecycleCallbacks]
class LessonProgress
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: Lesson::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Lesson $lesson = null;

    #[ORM\Column]
    private int $currentSlide = 0;

    #[ORM\Column(type: 'json')]
    private array $answers = [];

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $user, Lesson $lesson)
    {
        $this->user = $user;
        $this->lesson = $lesson;
        $this->startedAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): ?User { return $this->user; }
    public function getLesson(): ?Lesson { return $this->lesson; }

    public function getCurrentSlide(): int { return $this->currentSlide; }
    public function setCurrentSlide(int $i): static { $this->currentSlide = max(0, $i); return $this; }

    public function getAnswers(): array { return $this->answers; }
    public function setAnswers(array $answers): static { $this->answers = $answers; return $this; }

    public function getAnswer(string $slideId): array { return $this->answers[$slideId] ?? []; }
    public function setAnswer(string $slideId, array $data): static
    {
        $this->answers[$slideId] = array_merge($this->answers[$slideId] ?? [], $data);
        return $this;
    }

    public function getCompletedAt(): ?\DateTimeImmutable { return $this->completedAt; }
    public function setCompletedAt(?\DateTimeImmutable $at): static { $this->completedAt = $at; return $this; }
    public function isCompleted(): bool { return $this->completedAt !== null; }

    public function getStartedAt(): \DateTimeImmutable { return $this->startedAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function getPercent(): int
    {
        if ($this->isCompleted()) {
            return 100;
        }
        $n = $this->lesson?->getSlideCount() ?: 1;
        return (int) min(99, round($this->currentSlide / $n * 100));
    }
}
