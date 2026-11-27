<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\KlaviyoProfiles;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The coach's check-in emails (app:coach:followups): on or off, from the account or one click in the email. */
final class CoachEmailsController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly KlaviyoProfiles $klaviyo,
    ) {
    }

    #[Route('/account/coach-emails', name: 'app_account_coach_emails', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function toggle(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        if ($this->isCsrfTokenValid('coach-emails', (string) $request->request->get('_token'))) {
            $user->setCoachEmails($request->request->getBoolean('on'));
            $this->em->flush();
            $this->addFlash('success', $user->wantsCoachEmails() ? 'Coach check-in emails are on.' : 'Coach check-in emails are off.');
        }
        return $this->redirect($this->generateUrl('app_account') . '#coach-emails');
    }

    #[Route('/account/marketing-emails', name: 'app_account_marketing_emails', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function toggleMarketing(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        if ($this->isCsrfTokenValid('marketing-emails', (string) $request->request->get('_token'))) {
            $on = $request->request->getBoolean('on');
            $changed = $on !== $user->wantsMarketingEmails();
            $user->setMarketingEmails($on);
            $this->em->flush();
            if ($changed) {
                $this->klaviyo->setMarketingConsent($user->getEmail(), $user->getLocale(), $on);
            }
            $this->addFlash('success', $user->wantsMarketingEmails() ? 'Tips and offers by email are on.' : 'Tips and offers by email are off.');
        }
        return $this->redirect($this->generateUrl('app_account') . '#coach-emails');
    }

    /** "Unsubscribe" in the plan email series: signed, works without logging in. */
    #[Route('/emails/off', name: 'app_marketing_emails_off', methods: ['GET'])]
    public function marketingOff(Request $request, UriSigner $signer, UserRepository $users): Response
    {
        $user = $signer->checkRequest($request) ? $users->find((int) $request->query->get('u')) : null;
        if (!$user) {
            $this->addFlash('error', 'That link doesn’t work any more. You can switch the emails off in your account.');
            return $this->redirectToRoute('app_login');
        }
        $user->setMarketingEmails(false);
        $this->em->flush();
        $this->klaviyo->setMarketingConsent($user->getEmail(), $user->getLocale(), false);
        $this->addFlash('success', 'You’re unsubscribed from tips and offers.');
        return $this->redirectToRoute('app_homepage');
    }

    /** The link in the email: signed, so it works without logging in. */
    #[Route('/coach-emails/off', name: 'app_coach_emails_off', methods: ['GET'])]
    public function off(Request $request, UriSigner $signer, UserRepository $users): Response
    {
        $user = $signer->checkRequest($request) ? $users->find((int) $request->query->get('u')) : null;
        if (!$user) {
            $this->addFlash('error', 'That link doesn’t work any more. You can switch the emails off in your account.');
            return $this->redirectToRoute('app_login');
        }
        $user->setCoachEmails(false);
        $this->em->flush();
        $this->addFlash('success', 'Coach check-in emails are off.');
        return $this->redirectToRoute('app_homepage');
    }
}
