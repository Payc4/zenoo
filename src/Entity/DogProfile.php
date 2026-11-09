<?php

namespace App\Entity;

use App\Repository\DogProfileRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A pet parent's dog, from the Bowl Score quiz. One per account: taking the quiz again
 * replaces it. `data` holds the quiz's result fields (the same "quiz_*" fields the quiz
 * sends to Klaviyo), so the dashboard can show the score, the plan and the tips.
 */
#[ORM\Entity(repositoryClass: DogProfileRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_DOG_PROFILE_USER', fields: ['user'])]
class DogProfile
{
    /** Points available per part of the Bowl Score (public/quiz/scoring.js, CONFIG.weights). */
    public const SUB_MAX = ['protein' => 18, 'carbs' => 18, 'freshness' => 14, 'body' => 16, 'activity' => 14, 'signals' => 10, 'routine' => 10];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 60)]
    private string $name;

    #[ORM\Column(type: 'smallint')]
    private int $bowlScore = 0;

    /** Language the quiz was taken in; the tips in `data` are written in it. */
    #[ORM\Column(length: 5)]
    private string $locale = 'en';

    #[ORM\Column(type: 'json')]
    private array $data = [];

    /**
     * Answers to the chat check-in questions (config/chatbot/checkins.yaml), newest first per question:
     * { "stools": [ { "value": "soft", "text": "", "at": "2026-11-09" }, … ] }. Kept when the quiz is retaken.
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $checkins = null;

    /** When check-in questions were last shown in the chat (they come back every few days). */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $checkinAskedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $user)
    {
        $this->user = $user;
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getName(): string { return $this->name; }
    public function getBowlScore(): int { return $this->bowlScore; }
    public function getLocale(): string { return $this->locale; }
    public function getData(): array { return $this->data; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /** @return array<string, list<array{value: string, text: string, at: string}>> */
    public function getCheckins(): array { return $this->checkins ?? []; }
    public function getCheckinAskedAt(): ?\DateTimeImmutable { return $this->checkinAskedAt; }
    public function markCheckinAsked(\DateTimeImmutable $at): static { $this->checkinAskedAt = $at; return $this; }

    /** Records one check-in answer (an option value and/or typed text); keeps the last 5 per question. */
    public function addCheckin(string $id, string $value, string $text, \DateTimeImmutable $at): static
    {
        $all = $this->checkins ?? [];
        $list = $all[$id] ?? [];
        array_unshift($list, ['value' => $value, 'text' => $text, 'at' => $at->format('Y-m-d')]);
        $all[$id] = array_slice($list, 0, 5);
        $this->checkins = $all;
        return $this;
    }

    /** Fills the profile from a cleaned quiz result (see QuizResult::clean()). */
    public function fillFromQuiz(array $data, string $locale): static
    {
        $this->data = $data;
        $this->name = mb_substr(trim((string) ($data['quiz_dog_name'] ?? '')) ?: 'Your dog', 0, 60);
        $this->bowlScore = max(0, min(100, (int) ($data['quiz_bowl_score'] ?? 0)));
        $this->locale = $locale;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data['quiz_' . $key] ?? $default;
    }

    /** @return list<array{key: string, score: int, max: int, percent: int}> */
    public function subScores(): array
    {
        $out = [];
        foreach (self::SUB_MAX as $key => $max) {
            $score = max(0, min($max, (int) $this->get('sub_' . $key, 0)));
            $out[] = ['key' => $key, 'score' => $score, 'max' => $max, 'percent' => (int) round($score / $max * 100)];
        }
        return $out;
    }

    /** "great" | "good" | "look" | "wins" (the quiz's score bands). */
    public function band(): string
    {
        return (string) ($this->get('score_band') ?: match (true) {
            $this->bowlScore >= 80 => 'great',
            $this->bowlScore >= 60 => 'good',
            $this->bowlScore >= 40 => 'look',
            default => 'wins',
        });
    }
}
