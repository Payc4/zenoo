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

    /**
     * Fills the profile from a cleaned quiz result (see QuizResult::clean()). What was added
     * after the quiz (the photo, the food the coach saved: keys without "quiz_") is kept.
     */
    public function fillFromQuiz(array $data, string $locale): static
    {
        $kept = array_filter($this->data, fn ($k) => !str_starts_with((string) $k, 'quiz_'), ARRAY_FILTER_USE_KEY);
        $this->data = $data + $kept;
        $this->name = mb_substr(trim((string) ($data['quiz_dog_name'] ?? '')) ?: 'Your dog', 0, 60);
        $this->bowlScore = max(0, min(100, (int) ($data['quiz_bowl_score'] ?? 0)));
        $this->locale = $locale;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    /**
     * The food this dog eats, as far as we know it: what the coach read from a label or was told
     * in the chat (saved with setFood()), else the brand read from the label in the quiz.
     *
     * @return array{brand: string, product: string, format: string, notes: string, source: string, at: string}|null
     */
    public function getFood(): ?array
    {
        if (\is_array($this->data['food'] ?? null) && ($this->data['food']['brand'] ?? '') !== '') {
            return $this->data['food'];
        }
        $brand = trim((string) $this->get('food_brand', ''));
        if ($brand === '') {
            return null;
        }
        return ['brand' => $brand, 'product' => trim((string) $this->get('food_product', '')), 'format' => (string) $this->get('food_format', ''),
            'notes' => '', 'source' => 'quiz label photo', 'at' => $this->updatedAt->format('Y-m-d')];
    }

    public function setFood(string $brand, string $product, string $format, string $notes, string $source, \DateTimeImmutable $at): static
    {
        $clean = fn (string $v, int $n) => mb_substr(trim(strip_tags($v)), 0, $n);
        $this->data['food'] = [
            'brand' => $clean($brand, 80), 'product' => $clean($product, 120), 'format' => $clean($format, 40),
            'notes' => $clean($notes, 400), 'source' => $clean($source, 40), 'at' => $at->format('Y-m-d'),
        ];
        $this->updatedAt = $at;
        return $this;
    }

    /** How many facts the coach keeps about a dog (oldest drop off first). */
    public const MAX_NOTES = 30;

    /**
     * What the pet parent told the coach about their dog, newest last:
     * [ { "topic": "health", "text": "Itchy paws since spring", "at": "2026-11-17", "source": "chat" }, … ].
     * source: "chat" (saved by the coach) or "owner" (added on the dashboard). Kept when the quiz is retaken.
     *
     * @return list<array{topic: string, text: string, at: string, source?: string}>
     */
    public function getNotes(): array
    {
        return array_values(array_filter((array) ($this->data['notes'] ?? []), 'is_array'));
    }

    /**
     * Add a fact, or replace the one it updates (same topic and the old text given in $replaces).
     * Returns false for an empty or duplicate fact.
     */
    public function addNote(string $topic, string $text, \DateTimeImmutable $at, ?string $replaces = null, string $source = 'chat'): bool
    {
        $text = mb_substr(trim(strip_tags($text)), 0, 240);
        $topic = mb_substr(trim(strip_tags($topic)), 0, 30) ?: 'other';
        if ($text === '') {
            return false;
        }
        $notes = $this->getNotes();
        $norm = fn (string $s) => mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s)));
        foreach ($notes as $n) {
            if ($norm($n['text']) === $norm($text)) {
                return false;
            }
        }
        if ($replaces !== null && trim($replaces) !== '') {
            $notes = array_values(array_filter($notes, fn ($n) => $norm($n['text']) !== $norm($replaces)));
        }
        $notes[] = ['topic' => $topic, 'text' => $text, 'at' => $at->format('Y-m-d'), 'source' => \in_array($source, ['chat', 'owner'], true) ? $source : 'chat'];
        $this->data['notes'] = \array_slice($notes, -self::MAX_NOTES);
        return true;
    }

    /** Removes the fact at this position (as in getNotes()); the owner does this on the dashboard. */
    public function removeNote(int $index): bool
    {
        $notes = $this->getNotes();
        if (!isset($notes[$index])) {
            return false;
        }
        array_splice($notes, $index, 1);
        $this->data['notes'] = $notes;
        return true;
    }

    /**
     * Check-ins the coach planned ("two weeks in, what does Bella weigh?"), oldest first:
     * [ { "id": "a1b2c3", "due": "2026-12-01", "question": "…", "topic": "weight", "sent": null|"2026-12-01", "done": false } ].
     * Sent by email when due (app:coach:followups) and asked by the coach when they next open the chat.
     *
     * @return list<array{id: string, due: string, question: string, topic: string, sent: ?string, done: bool}>
     */
    public function getFollowUps(): array
    {
        return array_values(array_filter((array) ($this->data['followups'] ?? []), 'is_array'));
    }

    public const MAX_FOLLOWUPS = 3;

    /** Plans a check-in; one per topic (a new one replaces it), at most three open. */
    public function addFollowUp(string $question, int $inDays, string $topic, \DateTimeImmutable $now): array
    {
        $question = mb_substr(trim(strip_tags($question)), 0, 300);
        if ($question === '') {
            throw new \InvalidArgumentException('Empty question.');
        }
        $inDays = max(2, min(60, $inDays));
        $topic = mb_substr(trim($topic), 0, 30) ?: 'other';
        $open = array_values(array_filter($this->getFollowUps(), fn ($f) => !$f['done'] && $f['topic'] !== $topic));
        $item = ['id' => bin2hex(random_bytes(4)), 'due' => $now->modify("+$inDays days")->format('Y-m-d'), 'question' => $question, 'topic' => $topic, 'sent' => null, 'done' => false];
        $open[] = $item;
        $this->data['followups'] = \array_slice($open, -self::MAX_FOLLOWUPS);
        return $item;
    }

    /** The open check-in that is due (the oldest), if any. */
    public function dueFollowUp(\DateTimeImmutable $now): ?array
    {
        foreach ($this->getFollowUps() as $f) {
            if (!$f['done'] && $f['due'] <= $now->format('Y-m-d')) {
                return $f;
            }
        }
        return null;
    }

    public function markFollowUp(string $id, ?\DateTimeImmutable $sent = null, bool $done = false): void
    {
        $list = $this->getFollowUps();
        foreach ($list as &$f) {
            if ($f['id'] === $id) {
                if ($sent) {
                    $f['sent'] = $sent->format('Y-m-d');
                }
                $f['done'] = $f['done'] || $done;
            }
        }
        unset($f);
        // Keep the open ones and the last few done ones.
        $open = array_filter($list, fn ($f) => !$f['done']);
        $done = \array_slice(array_filter($list, fn ($f) => $f['done']), -5);
        $this->data['followups'] = array_values(array_merge($done, $open));
    }

    /** Clicks on the personal plan's starter links: {topper: n, half: n, full: n, last: "2026-11-18"}. */
    public function recordPlanClick(string $option, \DateTimeImmutable $at): void
    {
        $clicks = (array) ($this->data['plan_clicks'] ?? []);
        $clicks[$option] = (int) ($clicks[$option] ?? 0) + 1;
        $clicks['last'] = $at->format('Y-m-d');
        $this->data['plan_clicks'] = $clicks;
    }

    public function getPlanClicks(): array
    {
        return (array) ($this->data['plan_clicks'] ?? []);
    }

    /** The weight the pet parent told the coach since the quiz, if newer: { "kg": 31.5, "at": "2026-11-17" }. */
    public function getWeightUpdate(): ?array
    {
        return \is_array($this->data['weight'] ?? null) ? $this->data['weight'] : null;
    }

    public function setWeightUpdate(float $kg, \DateTimeImmutable $at): static
    {
        $this->data['weight'] = ['kg' => round($kg, 1), 'at' => $at->format('Y-m-d')];
        return $this;
    }

    /** File name of the dog's photo in public/uploads/dogs (see DogPhotoUploader), if one was added. */
    public function getPhoto(): ?string
    {
        return \is_string($this->data['photo'] ?? null) ? $this->data['photo'] : null;
    }

    public function setPhoto(?string $file): static
    {
        if ($file === null) {
            unset($this->data['photo']);
        } else {
            $this->data['photo'] = $file;
        }
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
