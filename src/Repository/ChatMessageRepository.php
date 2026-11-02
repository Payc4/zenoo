<?php

namespace App\Repository;

use App\Entity\ChatConversation;
use App\Entity\ChatMessage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ChatMessage> */
class ChatMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChatMessage::class);
    }

    /**
     * The last $limit messages of a conversation, oldest first.
     *
     * @return ChatMessage[]
     */
    public function findLastInConversation(ChatConversation $conversation, int $limit): array
    {
        $rows = $this->createQueryBuilder('m')
            ->andWhere('m.conversation = :c')->setParameter('c', $conversation)
            ->orderBy('m.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();

        return array_reverse($rows);
    }

    /** @return array{replies: int, input: int, output: int} */
    public function usageSince(\DateTimeImmutable $since): array
    {
        $row = $this->createQueryBuilder('m')
            ->select('COUNT(m.id) AS replies, COALESCE(SUM(m.inputTokens), 0) AS input, COALESCE(SUM(m.outputTokens), 0) AS output')
            ->andWhere('m.role = :role')->setParameter('role', ChatMessage::ROLE_ASSISTANT)
            ->andWhere('m.createdAt >= :since')->setParameter('since', $since)
            ->getQuery()->getSingleResult();

        return ['replies' => (int) $row['replies'], 'input' => (int) $row['input'], 'output' => (int) $row['output']];
    }
}
