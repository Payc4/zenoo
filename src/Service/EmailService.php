<?php

namespace App\Service;

use App\Entity\Purchase;
use App\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Every email the site sends. Messages are queued (Messenger "async" transport) and sent
 * by the worker, so a slow mail provider never slows down a page.
 */
class EmailService
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly string $appUrl,
        private readonly string $fromEmail,
        private readonly string $fromName,
    ) {
    }

    public function sendWelcomeEmail(User $user): void
    {
        $this->send($user->getEmail(), 'Welcome to ZENOO', 'emails/welcome.html.twig', ['user' => $user]);
    }

    public function sendPurchaseConfirmation(Purchase $purchase): void
    {
        $user = $purchase->getUser();
        $this->send($user->getEmail(), 'Your ZENOO order #' . $purchase->getId(), 'emails/purchase_confirmation.html.twig', [
            'user' => $user,
            'purchase' => $purchase,
            'product' => $purchase->getProduct(),
        ]);
    }

    public function sendPasswordResetEmail(User $user, string $resetUrl): void
    {
        $this->send($user->getEmail(), 'Reset your ZENOO password', 'emails/password_reset.html.twig', [
            'user' => $user,
            'resetUrl' => $resetUrl,
        ]);
    }

    public function sendMagicLoginLink(User $user, string $loginUrl, \DateTimeInterface $expiresAt): void
    {
        $this->send($user->getEmail(), 'Your ZENOO login link', 'emails/magic_login.html.twig', [
            'user' => $user,
            'loginUrl' => $loginUrl,
            'expiresAt' => $expiresAt,
        ]);
    }

    public function sendPasswordChangedNotice(User $user): void
    {
        $this->send($user->getEmail(), 'Your ZENOO password was changed', 'emails/password_changed.html.twig', ['user' => $user]);
    }

    /** @param array<string, mixed> $context */
    private function send(string $to, string $subject, string $template, array $context): void
    {
        $email = (new TemplatedEmail())
            ->from(new Address($this->fromEmail, $this->fromName))
            ->to($to)
            ->subject($subject)
            ->htmlTemplate($template)
            ->context($context + ['appUrl' => rtrim($this->appUrl, '/')]);

        $this->mailer->send($email);
    }
}
