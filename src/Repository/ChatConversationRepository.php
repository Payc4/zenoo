<?php

namespace App\Repository;

use App\Entity\ChatConversation;
use App\Entity\DogProfile;
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

    /** The pet's chat (there is at most one per pet). */
    public function findForDog(DogProfile $dog): ?ChatConversation
    {
        return $this->findOneBy(['dog' => $dog]);
    }

    /**
     * The pet's chat, created when it has none. Two tabs racing to start the same chat end
     * with the same one: the unique dog_id column refuses the second.
     */
    public function findOrCreateForDog(DogProfile $dog): ChatConversation
    {
        if ($found = $this->findForDog($dog)) {
            return $found;
        }
        $em = $this->getEntityManager();
        $conversation = new ChatConversation($dog->getUser(), $dog);
        $em->persist($conversation);
        $em->flush();

        return $conversation;
    }

    /**
     * Ids of the pets that already have a chat.
     *
     * @return list<int>
     */
    public function dogIdsWithChat(User $user): array
    {
        $rows = $this->createQueryBuilder('c')->select('IDENTITY(c.dog) AS dog')
            ->andWhere('c.user = :user')->setParameter('user', $user)
            ->andWhere('c.dog IS NOT NULL')
            ->getQuery()->getScalarResult();

        return array_map(fn (array $r) => (int) $r['dog'], $rows);
    }

    /** The newest message in any of this person's chats (used to hold back check-in emails). */
    public function lastMessageAt(User $user): ?\DateTimeImmutable
    {
        $at = $this->getEntityManager()->createQuery('SELECT MAX(m.createdAt) FROM App\Entity\ChatMessage m WHERE m.user = :u')
            ->setParameter('u', $user)->getSingleScalarResult();

        return $at ? new \DateTimeImmutable((string) $at) : null;
    }

    /** Empties one pet's chat ("Clear chat"); the chat itself stays. The daily message count is kept. */
    public function clearMessages(ChatConversation $conversation): void
    {
        $this->getEntityManager()->createQuery('DELETE FROM App\Entity\ChatMessage m WHERE m.conversation = :c')
            ->setParameter('c', $conversation)->execute();
    }

    /** Removes every chat of this person (account deleted). */
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
