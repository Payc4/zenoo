<?php

namespace App\Quiz;

use App\Entity\DogProfile;
use App\Entity\QuizSubmission;
use App\Entity\User;
use App\Repository\DogProfileRepository;
use App\Repository\UserRepository;
use App\Service\PlanEmails;
use App\Tracking\Attribution;
use App\Tracking\Funnel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Turns a quiz result (QuizSubmission) into an account with the dog's profile: from the "confirm
 * your email" link, or on coming back from the trial's Stripe Checkout (the card stands in for the
 * email check there). An existing account with that email gets the dog added instead.
 */
final class QuizAccounts
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly DogProfileRepository $dogs,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly Funnel $funnel,
        private readonly PlanEmails $planEmails,
    ) {
    }

    /** The account that already uses this quiz result's email, if any. */
    public function existing(QuizSubmission $submission): ?User
    {
        return $this->users->findOneBy(['email' => $submission->getEmail()]);
    }

    /** @return array{User, bool, DogProfile} the account, whether it is new, and the dog */
    public function createFrom(QuizSubmission $submission): array
    {
        $user = $this->users->findOneBy(['email' => $submission->getEmail()]);
        $isNew = $user === null;
        if ($isNew) {
            $user = (new User())
                ->setEmail($submission->getEmail())
                ->setFirstName($submission->getFirstName())
                ->setLocale($submission->getLocale())
                ->setHasPassword(false);
            // Nobody knows this password: they log in with the email link or choose one on the dashboard.
            $user->setPassword($this->hasher->hashPassword($user, bin2hex(random_bytes(32))));
            $user->setAttribution($submission->getData()['_attribution'] ?? null);
            $this->em->persist($user);
            $this->funnel->record('account_created', Attribution::label($user->getAttribution()));
        }
        $dog = $this->dogs->saveQuizResult($user, array_diff_key($submission->getData(), ['_attribution' => 1, '_answers' => 1]), $submission->getLocale(), null, false, $submission->getData()['_answers'] ?? null);
        if (!empty($submission->getData()['quiz_marketing_consent'])) {
            $user->setMarketingEmails(true); // the quiz's tips-and-offers tick
        }
        $submission->confirm();
        $this->em->flush();
        $this->planEmails->sendPlan($user, $dog); // roadmap 04: in their language
        $this->em->flush();

        return [$user, $isNew, $dog];
    }
}
