<?php

namespace App\Chat;

use App\Entity\ChatUsage;
use App\Entity\User;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The daily message limit: each pet parent can send CHAT_DAILY_LIMIT messages (default 60)
 * per calendar day in CHAT_TIMEZONE. Counts live in chat_usage, so deleting a chat doesn't
 * reset them; a reply that fails is refunded. Admins are not limited (to test the prompt).
 */
class ChatQuota
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire('%env(int:default:chat.default_daily_limit:CHAT_DAILY_LIMIT)%')] private readonly int $dailyLimit,
        #[Autowire('%env(default:chat.default_timezone:CHAT_TIMEZONE)%')] private readonly string $timezone,
    ) {
    }

    public function dailyLimit(): int
    {
        return $this->dailyLimit;
    }

    public function isUnlimited(User $user): bool
    {
        return $user->isAdmin();
    }

    public function used(User $user): int
    {
        $row = $this->em->createQuery('SELECT u.messages FROM App\Entity\ChatUsage u WHERE u.user = :user AND u.day = :day')
            ->setParameters(['user' => $user, 'day' => $this->today()])
            ->getOneOrNullResult();

        return (int) ($row['messages'] ?? 0);
    }

    public function remaining(User $user): int
    {
        return max(0, $this->dailyLimit - $this->used($user));
    }

    /**
     * Counts one message if the pet parent still has one left today. The check and the
     * count are a single UPDATE, so two messages sent at the same moment can't both slip through.
     */
    public function consume(User $user): bool
    {
        $limit = $this->isUnlimited($user) ? PHP_INT_MAX : $this->dailyLimit;
        if ($limit < 1) {
            return false;
        }
        $day = $this->today();

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $updated = $this->em->createQuery('UPDATE App\Entity\ChatUsage u SET u.messages = u.messages + 1 WHERE u.user = :user AND u.day = :day AND u.messages < :limit')
                ->setParameters(['user' => $user, 'day' => $day, 'limit' => $limit])
                ->execute();
            if ($updated > 0) {
                return true;
            }

            $exists = $this->em->createQuery('SELECT COUNT(u.id) FROM App\Entity\ChatUsage u WHERE u.user = :user AND u.day = :day')
                ->setParameters(['user' => $user, 'day' => $day])
                ->getSingleScalarResult();
            if ($exists) {
                return false; // the row is there and already at the limit
            }

            try {
                $this->em->getConnection()->insert('chat_usage', ['user_id' => $user->getId(), 'day' => $day, 'messages' => 1]);

                return true;
            } catch (UniqueConstraintViolationException) {
                // Another request created today's row first; go round once more and update it.
            }
        }

        return false;
    }

    /** Gives a message back when the reply failed. */
    public function refund(User $user): void
    {
        $this->em->createQuery('UPDATE App\Entity\ChatUsage u SET u.messages = u.messages - 1 WHERE u.user = :user AND u.day = :day AND u.messages > 0')
            ->setParameters(['user' => $user, 'day' => $this->today()])
            ->execute();
    }

    public function today(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone($this->timezone)))->format('Y-m-d');
    }

    /** When the limit resets, e.g. "midnight Vilnius time". */
    public function resetLabel(): string
    {
        $city = str_replace('_', ' ', substr($this->timezone, strrpos($this->timezone, '/') + 1));

        return 'midnight ' . $city . ' time';
    }
}
