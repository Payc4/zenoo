<?php

namespace App\EventSubscriber;

use App\Entity\User;
use App\Repository\DogProfileRepository;
use App\Repository\QuizSubmissionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Signing in (any way) saves every quiz result still waiting for its email link to the account,
 * not just one of them (M12). Same rules as the link: the same name and breed update that dog,
 * anything else is a new dog, and a result already saved isn't saved twice.
 */
final class PendingQuizResultsSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly QuizSubmissionRepository $submissions,
        private readonly DogProfileRepository $dogs,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [LoginSuccessEvent::class => 'onLogin'];
    }

    public function onLogin(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }
        $names = [];
        foreach ($this->submissions->findPendingForEmail($user->getEmail()) as $submission) {
            $data = $submission->getData();
            $this->dogs->saveQuizResult($user, array_diff_key($data, ['_attribution' => 1, '_answers' => 1]), $submission->getLocale(), null, false, $data['_answers'] ?? null);
            $submission->confirm();
            $names[] = $submission->getDogName();
        }
        if (!$names) {
            return;
        }
        $this->em->flush();
        $session = $event->getRequest()->hasSession() ? $event->getRequest()->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            foreach (array_unique($names) as $name) {
                $session->getFlashBag()->add('success', $this->translator->trans('%dog%’s profile is saved to your account.', ['%dog%' => $name], null, $user->getLocale()));
            }
        }
    }
}
