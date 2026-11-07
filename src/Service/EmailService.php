<?php

namespace App\Service;

use App\Entity\Purchase;
use App\Entity\QuizSubmission;
use App\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Every email the site sends. Messages are queued (Messenger "async" transport) and sent
 * by the worker, so a slow mail provider never slows down a page.
 *
 * Each email is written in the recipient's language (User::getLocale(), "en" or "lt"):
 * the subject is translated here and the template is rendered in that locale.
 */
class EmailService
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly string $appUrl,
        private readonly string $fromEmail,
        private readonly string $fromName,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function sendWelcomeEmail(User $user): void
    {
        $this->send($user->getEmail(), 'Welcome to ZENOO', [], 'emails/welcome.html.twig', ['user' => $user], $user->getLocale());
    }

    public function sendPurchaseConfirmation(Purchase $purchase): void
    {
        $user = $purchase->getUser();
        $this->send($user->getEmail(), 'Your ZENOO order #%id%', ['%id%' => $purchase->getId()], 'emails/purchase_confirmation.html.twig', [
            'user' => $user,
            'purchase' => $purchase,
            'product' => $purchase->getProduct(),
        ], $user->getLocale());
    }

    public function sendPasswordResetEmail(User $user, string $resetUrl): void
    {
        $this->send($user->getEmail(), 'Reset your ZENOO password', [], 'emails/password_reset.html.twig', [
            'user' => $user,
            'resetUrl' => $resetUrl,
        ], $user->getLocale());
    }

    public function sendMagicLoginLink(User $user, string $loginUrl, \DateTimeInterface $expiresAt): void
    {
        $this->send($user->getEmail(), 'Your ZENOO login link', [], 'emails/magic_login.html.twig', [
            'user' => $user,
            'loginUrl' => $loginUrl,
            'expiresAt' => $expiresAt,
        ], $user->getLocale());
    }

    public function sendPasswordChangedNotice(User $user): void
    {
        $this->send($user->getEmail(), 'Your ZENOO password was changed', [], 'emails/password_changed.html.twig', ['user' => $user], $user->getLocale());
    }

    /** After the quiz: "confirm your email" with the link that creates the account. */
    public function sendQuizConfirmation(QuizSubmission $submission, string $confirmUrl): void
    {
        $this->send($submission->getEmail(), 'Confirm your email to see %dog%’s dashboard', ['%dog%' => $submission->getDogName()], 'emails/quiz_confirm.html.twig', [
            'firstName' => $submission->getFirstName(),
            'dogName' => $submission->getDogName(),
            'score' => (int) ($submission->getData()['quiz_bowl_score'] ?? 0),
            'confirmUrl' => $confirmUrl,
        ], $submission->getLocale());
    }

    /**
     * @param array<string, string|int> $subjectParams
     * @param array<string, mixed>      $context
     */
    private function send(string $to, string $subject, array $subjectParams, string $template, array $context, string $locale = 'en'): void
    {
        $email = (new TemplatedEmail())
            ->from(new Address($this->fromEmail, $this->fromName))
            ->to($to)
            ->subject($this->translator->trans($subject, $subjectParams, null, $locale))
            ->htmlTemplate($template)
            ->locale($locale)
            ->context($context + ['appUrl' => rtrim($this->appUrl, '/')]);

        $this->mailer->send($email);
    }
}
