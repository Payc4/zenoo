<?php

namespace App\Controller;

use App\Form\RegistrationFormType;
use App\Repository\UserRepository;
use App\Service\EmailService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class PasswordResetController extends AbstractController
{
    private const TOKEN_LIFETIME = '+1 hour';

    #[Route('/forgot-password', name: 'app_forgot_password', methods: ['GET', 'POST'])]
    public function request(Request $request, UserRepository $users, EntityManagerInterface $em, EmailService $emails, LoggerInterface $logger, TranslatorInterface $translator): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('forgot-password', (string) $request->request->get('_csrf_token'))) {
                $this->addFlash('error', 'Your session expired. Please try again.');

                return $this->redirectToRoute('app_forgot_password');
            }

            $user = $users->findOneByEmail((string) $request->request->get('email', ''));
            $recent = $user?->getResetTokenExpiresAt() && $user->getResetTokenExpiresAt() > new \DateTimeImmutable('+55 minutes');
            if ($user && !$recent) {
                $token = bin2hex(random_bytes(32));
                $user->setResetToken($token, new \DateTimeImmutable(self::TOKEN_LIFETIME));
                $em->flush();
                try {
                    $emails->sendPasswordResetEmail($user, $this->generateUrl('app_reset_password', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL));
                } catch (\Throwable $e) {
                    $logger->error('Password reset email failed', ['user' => $user->getId(), 'error' => $e->getMessage()]);
                }
            }

            // The same answer either way, so this form can't be used to find out who has an account.
            $this->addFlash('success', 'If there’s a ZENOO account for that email, we’ve sent a link to reset the password. It works for one hour.');

            return $this->redirectToRoute('app_forgot_password');
        }

        return $this->render('auth/forgot_password.html.twig');
    }

    #[Route('/reset-password/{token}', name: 'app_reset_password', requirements: ['token' => '[a-f0-9]{64}'], methods: ['GET', 'POST'])]
    public function reset(string $token, Request $request, UserRepository $users, EntityManagerInterface $em, UserPasswordHasherInterface $hasher, EmailService $emails, LoggerInterface $logger, TranslatorInterface $translator): Response
    {
        $user = $users->findOneByResetToken($token);
        if (!$user || !$user->getResetTokenExpiresAt() || $user->getResetTokenExpiresAt() < new \DateTimeImmutable()) {
            $this->addFlash('error', 'That reset link has expired or was already used. Please ask for a new one.');

            return $this->redirectToRoute('app_forgot_password');
        }

        $error = null;
        if ($request->isMethod('POST')) {
            $password = (string) $request->request->get('password', '');
            if (!$this->isCsrfTokenValid('reset-password', (string) $request->request->get('_csrf_token'))) {
                $error = 'Your session expired. Please try again.';
            } elseif (strlen($password) < RegistrationFormType::MIN_PASSWORD) {
                $error = $translator->trans('Your password needs at least %n% characters.', ['%n%' => RegistrationFormType::MIN_PASSWORD]);
            } elseif ($password !== (string) $request->request->get('password_confirm', '')) {
                $error = 'The two passwords don’t match.';
            } else {
                $user->setPassword($hasher->hashPassword($user, $password));
                $user->setResetToken(null);
                // Also invalidates any email login link that is still out there.
                $user->setLastMagicLinkRequestedAt(new \DateTimeImmutable());
                $em->flush();
                try {
                    $emails->sendPasswordChangedNotice($user);
                } catch (\Throwable $e) {
                    $logger->error('Password-changed email failed', ['user' => $user->getId(), 'error' => $e->getMessage()]);
                }

                $this->addFlash('success', 'Your new password is set. You can log in now.');

                return $this->redirectToRoute('app_login');
            }
        }

        return $this->render('auth/reset_password.html.twig', [
            'token' => $token,
            'error' => $error,
        ], new Response(null, $error ? 422 : 200));
    }
}
