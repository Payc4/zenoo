<?php

namespace App\Entity;

use App\Repository\ChatMessageRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One message in a chat conversation. role is "user" or "assistant".
 * Token counts are kept on assistant messages so the admin can see usage and cost.
 */
#[ORM\Entity(repositoryClass: ChatMessageRepository::class)]
#[ORM\Index(name: 'IDX_CHAT_MSG_USER_ROLE_CREATED', fields: ['user', 'role', 'createdAt'])]
#[ORM\Index(name: 'IDX_CHAT_MSG_ROLE_CREATED', fields: ['role', 'createdAt'])]
class ChatMessage
{
    public const ROLE_USER = 'user';
    public const ROLE_ASSISTANT = 'assistant';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ChatConversation::class, inversedBy: 'messages')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ChatConversation $conversation;

    /** Copied from the conversation so the daily limit is one indexed count. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 16)]
    private string $role;

    #[ORM\Column(type: 'text')]
    private string $content;

    /** Question cards shown with this assistant message (see App\Chat\Questions), if any. */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $questions = null;

    /**
     * Photos and files sent with this message (see App\Chat\ChatFiles):
     * list of {file, type, name, size}. The files themselves are private, on the uploads volume.
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $attachments = null;

    #[ORM\Column(nullable: true)]
    private ?int $inputTokens = null;

    #[ORM\Column(nullable: true)]
    private ?int $outputTokens = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(ChatConversation $conversation, string $role, string $content)
    {
        $this->conversation = $conversation;
        $this->user = $conversation->getUser();
        $this->role = $role;
        $this->content = $content;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getConversation(): ChatConversation
    {
        return $this->conversation;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function isFromUser(): bool
    {
        return $this->role === self::ROLE_USER;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getQuestions(): ?array
    {
        return $this->questions;
    }

    public function setQuestions(?array $questions): static
    {
        $this->questions = $questions ?: null;

        return $this;
    }

    /** @return list<array{file: string, type: string, name: string, size: int}> */
    public function getAttachments(): array
    {
        return $this->attachments ?? [];
    }

    public function setAttachments(array $attachments): static
    {
        $this->attachments = $attachments ? array_values($attachments) : null;

        return $this;
    }

    /** For tests and data fixes only. */
    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getInputTokens(): ?int
    {
        return $this->inputTokens;
    }

    public function getOutputTokens(): ?int
    {
        return $this->outputTokens;
    }

    public function setUsage(?int $input, ?int $output): static
    {
        $this->inputTokens = $input;
        $this->outputTokens = $output;

        return $this;
    }
}
