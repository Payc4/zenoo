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

    /**
     * The pet parent's chat. There is one per person; if older data has several,
     * the most recently used one is the chat.
     */
    public function findForUser(User $user): ?ChatConversation
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.user = :user')->setParameter('user', $user)
            ->orderBy('c.updatedAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    /** Empties the chat ("Clear chat"). The daily message count is kept. */
    public function deleteConversationsForUser(User $user): void
    {
        $em = $this->getEntityManager();
        $em->createQuery('DELETE FROM App\Entity\ChatMessage m WHERE m.user = :u')->setParameter('u', $user)->execute();
        $em->createQuery('DELETE FROM App\Entity\ChatConversation c WHERE c.user = :u')->setParameter('u', $user)->execute();
    }

    /** Removes the chat and the usage counts (used when the account is deleted). */
    public function deleteAllForUser(User $user): void
    {
        $this->deleteConversationsForUser($user);
        $this->getEntityManager()->createQuery('DELETE FROM App\Entity\ChatUsage u WHERE u.user = :u')->setParameter('u', $user)->execute();
    }
}
