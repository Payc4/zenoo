<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * How many chat messages a pet parent sent on one day. Kept apart from the messages
 * themselves, so deleting a chat doesn't give the day's messages back.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'UNIQ_CHAT_USAGE_USER_DAY', fields: ['user', 'day'])]
class ChatUsage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** The calendar day in CHAT_TIMEZONE, e.g. "2026-11-02". */
    #[ORM\Column(length: 10)]
    private string $day;

    #[ORM\Column]
    private int $messages = 0;

    public function __construct(User $user, string $day, int $messages = 0)
    {
        $this->user = $user;
        $this->day = $day;
        $this->messages = $messages;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getDay(): string
    {
        return $this->day;
    }

    public function getMessages(): int
    {
        return $this->messages;
    }
}
