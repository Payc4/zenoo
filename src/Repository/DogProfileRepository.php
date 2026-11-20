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

    /** The account's dog with this name (any capitals), if there is one. */
    public function findByName(User $user, string $name): ?DogProfile
    {
        $name = mb_strtolower(trim($name));
        foreach ($this->findAllForUser($user) as $dog) {
            if ($name !== '' && mb_strtolower(trim($dog->getName())) === $name) {
                return $dog;
            }
        }
        return null;
    }

    /**
     * Saves a quiz result (not flushed) and selects that dog. Never matched by name alone (F1):
     * - $dogId: a retake for that dog ("Retake the quiz for Max", or "Update Max" when asked);
     * - $asNew: "+ Add another dog", or "Add a second Max";
     * - neither (a result confirmed by email or attached at sign-in, where nobody can be asked):
     *   the same name AND the same breed is a retake of that dog, anything else is a new dog.
     * The same result saved twice (sign-in and the email link, say) changes nothing the second time.
     *
     * @param array|null $answers the quiz's own answers, so a retake starts from them (N2)
     */
    public function saveQuizResult(User $user, array $data, string $locale, ?int $dogId = null, bool $asNew = false, ?array $answers = null): DogProfile
    {
        $dogs = $this->findAllForUser($user);
        $resultId = (string) ($data['quiz_result_id'] ?? '');
        foreach ($dogs as $dog) {
            if ($resultId !== '' && $dog->get('result_id') === $resultId) {
                $user->setActiveDogId($dog->getId());
                return $dog;
            }
        }
        $profile = null;
        if ($dogId !== null) {
            foreach ($dogs as $dog) {
                if ($dog->getId() === $dogId) {
                    $profile = $dog;
                }
            }
        } elseif (!$asNew) {
            $same = $this->findByName($user, (string) ($data['quiz_dog_name'] ?? ''));
            $breed = static fn ($b) => mb_strtolower(trim((string) $b));
            if ($same && $breed($same->get('breed')) === $breed($data['quiz_breed'] ?? '')) {
                $profile = $same;
            }
        }
        $profile ??= new DogProfile($user);
        $profile->fillFromQuiz($data, $locale);
        if ($answers !== null) {
            $profile->setQuizAnswers($answers);
        }
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
