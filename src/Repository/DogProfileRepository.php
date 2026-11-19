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

    /** The selected dog (see User::getActiveDogId()), else the one updated most recently. */
    public function findForUser(User $user): ?DogProfile
    {
        $dogs = $this->findAllForUser($user);
        foreach ($dogs as $dog) {
            if ($dog->getId() === $user->getActiveDogId()) {
                return $dog;
            }
        }
        return $dogs[0] ?? null;
    }

    /** @return list<DogProfile> the account's dogs, most recently updated first */
    public function findAllForUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['updatedAt' => 'DESC', 'id' => 'DESC']);
    }

    /**
     * Saves a quiz result (not flushed): to the dog with that id, else to the account's dog with
     * the same name (a retake), else as a new dog (another dog in the household). The saved dog
     * becomes the selected one.
     */
    public function saveQuizResult(User $user, array $data, string $locale, ?int $dogId = null): DogProfile
    {
        $dogs = $this->findAllForUser($user);
        $name = mb_strtolower(trim((string) ($data['quiz_dog_name'] ?? '')));
        $profile = null;
        foreach ($dogs as $dog) {
            if (($dogId !== null && $dog->getId() === $dogId) || ($dogId === null && $name !== '' && mb_strtolower(trim($dog->getName())) === $name)) {
                $profile = $dog;
                break;
            }
        }
        $profile ??= new DogProfile($user);
        $profile->fillFromQuiz($data, $locale);
        $this->getEntityManager()->persist($profile);
        if ($profile->getId() === null) {
            $this->getEntityManager()->flush(); // for the id
        }
        $user->setActiveDogId($profile->getId());
        return $profile;
    }

    public function deleteForUser(User $user): void
    {
        $this->createQueryBuilder('d')->delete()->where('d.user = :u')->setParameter('u', $user)->getQuery()->execute();
    }
}
