<?php

namespace App\Repository;

use App\Entity\ChatConversation;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ChatConversation> */
class ChatConversationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChatConversation::class);
    }

    /** @return ChatConversation[] */
    public function findRecentForUser(User $user, int $limit = 40): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.user = :user')->setParameter('user', $user)
            ->orderBy('c.updatedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    public function findOneForUser(int $id, User $user): ?ChatConversation
    {
        return $this->findOneBy(['id' => $id, 'user' => $user]);
    }

    /** Removes all of a user's chats and usage counts (used when the account is deleted). */
    public function deleteAllForUser(User $user): void
    {
        $em = $this->getEntityManager();
        $em->createQuery('DELETE FROM App\Entity\ChatMessage m WHERE m.user = :u')->setParameter('u', $user)->execute();
        $em->createQuery('DELETE FROM App\Entity\ChatConversation c WHERE c.user = :u')->setParameter('u', $user)->execute();
        $em->createQuery('DELETE FROM App\Entity\ChatUsage u WHERE u.user = :u')->setParameter('u', $user)->execute();
    }
}
