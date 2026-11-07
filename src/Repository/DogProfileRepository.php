<?php

namespace App\Repository;

use App\Entity\DogProfile;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<DogProfile> */
class DogProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DogProfile::class);
    }

    public function findForUser(User $user): ?DogProfile
    {
        return $this->findOneBy(['user' => $user]);
    }

    /** Creates the user's dog profile or replaces it with a newer quiz result (not flushed). */
    public function saveQuizResult(User $user, array $data, string $locale): DogProfile
    {
        $profile = $this->findForUser($user) ?? new DogProfile($user);
        $profile->fillFromQuiz($data, $locale);
        $this->getEntityManager()->persist($profile);
        return $profile;
    }

    public function deleteForUser(User $user): void
    {
        $this->createQueryBuilder('d')->delete()->where('d.user = :u')->setParameter('u', $user)->getQuery()->execute();
    }
}
