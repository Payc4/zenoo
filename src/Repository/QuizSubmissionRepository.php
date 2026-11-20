<?php

namespace App\Repository;

use App\Entity\QuizSubmission;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<QuizSubmission> */
class QuizSubmissionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QuizSubmission::class);
    }

    public function findByToken(string $token): ?QuizSubmission
    {
        return $token === '' ? null : $this->findOneBy(['tokenHash' => hash('sha256', $token)]);
    }

    public function countSince(string $field, string $value, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('q')->select('COUNT(q.id)')
            ->where("q.$field = :v")->andWhere('q.createdAt >= :since')
            ->setParameter('v', $value)->setParameter('since', $since)
            ->getQuery()->getSingleScalarResult();
    }

    public function deleteForEmail(string $email): void
    {
        $this->createQueryBuilder('q')->delete()->where('q.email = :e')->setParameter('e', mb_strtolower($email))->getQuery()->execute();
    }

    /**
     * Every result for this email still waiting for its link, oldest first.
     *
     * @return list<QuizSubmission>
     */
    public function findPendingForEmail(string $email): array
    {
        return $this->createQueryBuilder('q')
            ->where('q.email = :e')->andWhere('q.confirmedAt IS NULL')->andWhere('q.createdAt >= :cutoff')
            ->setParameter('e', mb_strtolower($email))
            ->setParameter('cutoff', new \DateTimeImmutable('-' . ltrim(QuizSubmission::LIFETIME, '+')))
            ->orderBy('q.createdAt', 'ASC')->addOrderBy('q.id', 'ASC')
            ->getQuery()->getResult();
    }

    /** Housekeeping: unconfirmed submissions older than their link's lifetime. */
    public function deleteExpired(): int
    {
        return $this->createQueryBuilder('q')->delete()
            ->where('q.confirmedAt IS NULL')->andWhere('q.createdAt < :cutoff')
            ->setParameter('cutoff', new \DateTimeImmutable('-' . ltrim(QuizSubmission::LIFETIME, '+')))
            ->getQuery()->execute();
    }
}
