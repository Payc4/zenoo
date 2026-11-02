<?php

namespace App\Entity;

use App\Repository\ChatConversationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/** One chat thread between a pet parent and the ZENOO chatbot (/chat). */
#[ORM\Entity(repositoryClass: ChatConversationRepository::class)]
#[ORM\Index(name: 'IDX_CHAT_CONV_USER_UPDATED', fields: ['user', 'updatedAt'])]
class ChatConversation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 120)]
    private string $title = 'New conversation';

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, ChatMessage> */
    #[ORM\OneToMany(targetEntity: ChatMessage::class, mappedBy: 'conversation', cascade: ['remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $messages;

    public function __construct(User $user)
    {
        $this->user = $user;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->messages = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    /** Uses the first message as the title, shortened to fit the sidebar. */
    public function setTitle(string $title): static
    {
        $title = trim((string) preg_replace('/\s+/u', ' ', $title));
        $this->title = mb_strlen($title) > 60 ? rtrim(mb_substr($title, 0, 57)) . '…' : ($title !== '' ? $title : 'New conversation');

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    /** @return Collection<int, ChatMessage> */
    public function getMessages(): Collection
    {
        return $this->messages;
    }
}
