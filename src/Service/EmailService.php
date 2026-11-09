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
 * Every email the site sends, delivered one of two ways (EMAIL_DELIVERY):
 *
 * - "smtp": the site writes the email itself (templates/emails/) and hands it to MAILER_DSN.
 *   Messages are queued (Messenger "async" transport) and sent by the worker.
 * - "klaviyo": the site records a Klaviyo event instead ("ZENOO Password Reset" etc., see EVENTS),
 *   with everything the email needs as event properties, and a Klaviyo flow per event sends it.
 * - "auto" (default): Klaviyo when MAILER_DSN isn't set (null://) and KLAVIYO_PRIVATE_KEY is,
 *   otherwise SMTP.
 *
 * Each email is in the recipient's language (User::getLocale(), "en" or "lt"): the subject is
 * translated here, the template rendered in that locale, and Klaviyo events carry "locale" so a
 * flow can split on it.
 */
class EmailService
{
    /** Klaviyo metric names, one flow each. Also listed in DEPLOY.md. */
    public const EVENTS = [
        'welcome' => 'ZENOO Welcome',
        'order' => 'ZENOO Order Confirmation',
        'reset' => 'ZENOO Password Reset',
        'login' => 'ZENOO Login Link',
        'changed' => 'ZENOO Password Changed',
        'quiz' => 'ZENOO Quiz Confirmation',
    ];

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly string $appUrl,
        private readonly string $fromEmail,
        private readonly string $fromName,
        private readonly TranslatorInterface $translator,
        private readonly KlaviyoProfiles $klaviyo,
        private readonly string $mailerDsn = 'null://null',
        private readonly string $delivery = 'auto',
    ) {
    }

    /** "klaviyo" or "smtp" for an email in this language. */
    public function deliveryFor(string $locale): string
    {
        $mode = strtolower(trim($this->delivery));
        if ($mode === 'klaviyo' || $mode === 'smtp') {
            return $mode;
        }
        $noSmtp = $this->mailerDsn === '' || str_starts_with($this->mailerDsn, 'null://');
        return $noSmtp && $this->klaviyo->canSendEvents($locale) ? 'klaviyo' : 'smtp';
    }

    public function sendWelcomeEmail(User $user): void
    {
        $this->send($user->getEmail(), 'Welcome to ZENOO', [], 'emails/welcome.html.twig', ['user' => $user], $user->getLocale(), 'welcome', [
            'dashboard_url' => $this->url('/dashboard'),
            'quiz_url' => $this->url('/quiz/'),
            'courses_url' => $this->url('/courses'),
        ], $user);
    }

    public function sendPurchaseConfirmation(Purchase $purchase): void
    {
        $user = $purchase->getUser();
        $this->send($user->getEmail(), 'Your ZENOO order #%id%', ['%id%' => $purchase->getId()], 'emails/purchase_confirmation.html.twig', [
            'user' => $user,
            'purchase' => $purchase,
            'product' => $purchase->getProduct(),
        ], $user->getLocale(), 'order', [
            'order_id' => $purchase->getId(),
            'product_name' => $purchase->getProduct()?->getName(),
            'amount' => (float) $purchase->getAmount(),
            'discount' => $purchase->getDiscountAmount() !== null ? (float) $purchase->getDiscountAmount() : null,
            'currency' => strtoupper($purchase->getCurrency()),
            'order_date' => $purchase->getCreatedAt()->format('Y-m-d'),
            'order_url' => $this->url('/account/orders/' . $purchase->getId()),
            'test_order' => $purchase->isTestMode(),
        ], $user);
    }

    public function sendPasswordResetEmail(User $user, string $resetUrl): void
    {
        $this->send($user->getEmail(), 'Reset your ZENOO password', [], 'emails/password_reset.html.twig', [
            'user' => $user,
            'resetUrl' => $resetUrl,
        ], $user->getLocale(), 'reset', ['reset_link' => $resetUrl], $user);
    }

    public function sendMagicLoginLink(User $user, string $loginUrl, \DateTimeInterface $expiresAt): void
    {
        $this->send($user->getEmail(), 'Your ZENOO login link', [], 'emails/magic_login.html.twig', [
            'user' => $user,
            'loginUrl' => $loginUrl,
            'expiresAt' => $expiresAt,
        ], $user->getLocale(), 'login', [
            'login_link' => $loginUrl,
            'expires_in_minutes' => max(1, (int) round(($expiresAt->getTimestamp() - time()) / 60)),
        ], $user);
    }

    public function sendPasswordChangedNotice(User $user): void
    {
        $this->send($user->getEmail(), 'Your ZENOO password was changed', [], 'emails/password_changed.html.twig', ['user' => $user], $user->getLocale(), 'changed', [
            'account_url' => $this->url('/account'),
            'forgot_password_url' => $this->url('/forgot-password'),
        ], $user);
    }

    /** After the quiz: "confirm your email" with the link that creates the account. */
    public function sendQuizConfirmation(QuizSubmission $submission, string $confirmUrl): void
    {
        $this->send($submission->getEmail(), 'Confirm your email to see %dog%’s dashboard', ['%dog%' => $submission->getDogName()], 'emails/quiz_confirm.html.twig', [
            'firstName' => $submission->getFirstName(),
            'dogName' => $submission->getDogName(),
            'score' => (int) ($submission->getData()['quiz_bowl_score'] ?? 0),
            'confirmUrl' => $confirmUrl,
        ], $submission->getLocale(), 'quiz', [
            'confirm_link' => $confirmUrl,
            'dog_name' => $submission->getDogName(),
            'bowl_score' => (int) ($submission->getData()['quiz_bowl_score'] ?? 0),
        ], null, $submission->getFirstName());
    }

    /**
     * @param array<string, string|int>        $subjectParams
     * @param array<string, mixed>             $context    for the site's own template (SMTP)
     * @param array<string, scalar|null>       $properties for the Klaviyo event
     */
    private function send(string $to, string $subject, array $subjectParams, string $template, array $context, string $locale, string $event, array $properties, ?User $user, ?string $firstName = null): void
    {
        $subjectText = $this->translator->trans($subject, $subjectParams, null, $locale);

        if ($this->deliveryFor($locale) === 'klaviyo') {
            $firstName ??= $user?->getFirstName();
            $ok = $this->klaviyo->trackEvent($to, $locale, self::EVENTS[$event], $properties + [
                'subject' => $subjectText,
                'first_name' => $firstName,
                'locale' => $locale,
                'site_url' => rtrim($this->appUrl, '/'),
            ], ['first_name' => $firstName, 'locale' => $locale]);
            if (!$ok) {
                throw new \RuntimeException(sprintf('Klaviyo did not accept the "%s" event.', self::EVENTS[$event]));
            }
            return;
        }

        $email = (new TemplatedEmail())
            ->from(new Address($this->fromEmail, $this->fromName))
            ->to($to)
            ->subject($subjectText)
            ->htmlTemplate($template)
            ->locale($locale)
            ->context($context + ['appUrl' => rtrim($this->appUrl, '/')]);

        $this->mailer->send($email);
    }

    private function url(string $path): string
    {
        return rtrim($this->appUrl, '/') . $path;
    }
}
