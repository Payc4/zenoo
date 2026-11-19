<?php

namespace App\Service;

use App\Entity\Purchase;
use App\Entity\QuizSubmission;
use App\Entity\User;
use App\Twig\LocaleExtension;
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
 * Each email is in the recipient's language (User::getLocale(): en, lt, uk or pl): the subject is
 * translated here and the template rendered in that locale. Klaviyo events also carry the email's
 * whole text, already translated, as copy_* properties (copy_heading, copy_intro, copy_button, …;
 * see copy()), so one Klaviyo template per flow works in every language: {{ event.copy_heading }}.
 * The ready-made templates are in docs/klaviyo/. They also carry "locale" for flows that split on it.
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
        'followup' => 'ZENOO Coach Check-in',
        'plan' => 'ZENOO Plan',
        'series' => 'ZENOO Plan Series',
    ];

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly string $appUrl,
        private readonly string $fromEmail,
        private readonly string $fromName,
        private readonly TranslatorInterface $translator,
        private readonly KlaviyoProfiles $klaviyo,
        private readonly LocaleExtension $dates,
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
        $l = $user->getLocale();
        $this->send($user->getEmail(), 'Welcome to ZENOO', [], 'emails/welcome.html.twig', ['user' => $user], $l, 'welcome', [
            'dashboard_url' => $this->url('/dashboard'),
            'quiz_url' => $this->url('/quiz/'),
            'courses_url' => $this->url('/courses'),
        ] + $this->copy($l, [
            'heading' => ['Welcome, %name%!', ['%name%' => $user->getDisplayName()]],
            'intro' => 'Your ZENOO account is ready. From now on you can see your orders and update your details in one place.',
            'text' => 'Not sure if your dog’s bowl is on track? The free Bowl Score quiz takes about three minutes.',
            'button' => 'Take the Bowl Score quiz',
            'note' => ['Log in any time at %link% with %email%.', ['%link%' => $this->host() . '/login', '%email%' => $user->getEmail()]],
        ]), $user);
    }

    public function sendPurchaseConfirmation(Purchase $purchase): void
    {
        $user = $purchase->getUser();
        $l = $user->getLocale();
        $this->send($user->getEmail(), 'Your ZENOO order #%id%', ['%id%' => $purchase->getId()], 'emails/purchase_confirmation.html.twig', [
            'user' => $user,
            'purchase' => $purchase,
            'product' => $purchase->getProduct(),
        ], $l, 'order', [
            'order_id' => $purchase->getId(),
            'product_name' => $purchase->getProduct()?->getName(),
            'amount' => (float) $purchase->getAmount(),
            'discount' => $purchase->getDiscountAmount() !== null ? (float) $purchase->getDiscountAmount() : null,
            'currency' => strtoupper($purchase->getCurrency()),
            'order_date' => $purchase->getCreatedAt()->format('Y-m-d'),
            'order_url' => $this->url('/account/orders/' . $purchase->getId()),
            'test_order' => $purchase->isTestMode(),
        ] + $this->copy($l, [
            'heading' => ['Thank you, %name%!', ['%name%' => $user->getDisplayName()]],
            'intro' => 'Your order is confirmed. Here’s your receipt.',
            'test_label' => 'Test order:',
            'test_text' => 'paid in Stripe test mode, so no real money was taken.',
            'order_label' => 'Order',
            'product_label' => 'Product',
            'date_label' => 'Date',
            'discount_label' => 'Discount',
            'total_label' => 'Total paid',
            'button' => 'View my order',
            'note' => 'Questions about your order? Write to support@myzenoo.com.',
        ]) + ['copy_date' => $this->dates->format($purchase->getCreatedAt(), 'long', $l)], $user);
    }

    public function sendPasswordResetEmail(User $user, string $resetUrl): void
    {
        $this->send($user->getEmail(), 'Reset your ZENOO password', [], 'emails/password_reset.html.twig', [
            'user' => $user,
            'resetUrl' => $resetUrl,
        ], $user->getLocale(), 'reset', ['reset_link' => $resetUrl] + $this->copy($user->getLocale(), [
            'heading' => 'Reset your password',
            'intro' => ['Hi %name%, someone asked to reset the password for your ZENOO account. If it was you, choose a new one here:', ['%name%' => $user->getDisplayName()]],
            'button' => 'Choose a new password',
            'note' => 'The link works for one hour. If you didn’t ask for this, you can ignore this email: your password stays the same.',
        ]), $user);
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
        ] + $this->copy($user->getLocale(), [
            'heading' => 'Your login link',
            'intro' => ['Hi %name%, tap the button to log in to ZENOO. No password needed.', ['%name%' => $user->getDisplayName()]],
            'button' => 'Log me in',
            'note' => 'The link works once, for 10 minutes. If you didn’t ask for it, you can ignore this email.',
        ]), $user);
    }

    public function sendPasswordChangedNotice(User $user): void
    {
        $this->send($user->getEmail(), 'Your ZENOO password was changed', [], 'emails/password_changed.html.twig', ['user' => $user], $user->getLocale(), 'changed', [
            'account_url' => $this->url('/account'),
            'forgot_password_url' => $this->url('/forgot-password'),
        ] + $this->copy($user->getLocale(), [
            'heading' => 'Password changed',
            'intro' => ['Hi %name%, the password for your ZENOO account was just changed.', ['%name%' => $user->getDisplayName()]],
            'text' => 'If that was you, there’s nothing else to do. If it wasn’t, reset it now and write to support@myzenoo.com.',
            'button' => 'Reset my password',
        ]), $user);
    }

    /**
     * A check-in the coach planned ("two weeks in, what does Bella weigh?"): the question and a
     * button into the chat, where the coach asks it again. Switched off with $offUrl.
     */
    public function sendCoachFollowUp(User $user, string $dogName, string $question, string $chatUrl, string $offUrl): void
    {
        $locale = $user->getLocale();
        $this->send($user->getEmail(), 'A quick question about %dog%', ['%dog%' => $dogName], 'emails/coach_followup.html.twig', [
            'user' => $user, 'dogName' => $dogName, 'question' => $question, 'chatUrl' => $chatUrl, 'offUrl' => $offUrl,
        ], $locale, 'followup', [
            'dog_name' => $dogName,
            'question' => $question,
            'chat_url' => $chatUrl,
            'off_url' => $offUrl,
        ] + $this->copy($locale, [
            'heading' => ['How is %dog% doing?', ['%dog%' => $dogName]],
            'greeting' => $user->getFirstName() ? ['Hi %name%,', ['%name%' => $user->getFirstName()]] : 'Hi,',
            'intro' => 'Your ZENOO coach wanted to check in:',
            'button' => 'Answer in the chat',
            'note' => 'Your answer helps the coach keep the advice right for your dog.',
            'off' => 'Don’t want these check-ins? Switch them off',
        ]), $user);
    }

    /**
     * Roadmap 04: the plan they asked for, right after the account is made. $plan is the plan
     * email's content from PlanEmails (score, fixes, daily plan, ZENOO pick); $urls has dashboard and plan links.
     */
    public function sendPlanEmail(User $user, array $plan, array $urls): void
    {
        $locale = $user->getLocale();
        $dog = $plan['dog'];
        $zenoo = $plan['zenoo'] ?? null;
        $this->send($user->getEmail(), '%dog%’s Bowl Score and plan', ['%dog%' => $dog], 'emails/plan.html.twig', ['user' => $user, 'plan' => $plan, 'urls' => $urls], $locale, 'plan', [
            'dog_name' => $dog,
            'bowl_score' => $plan['score'],
            'fix_1' => $plan['fixes'][0] ?? '',
            'fix_2' => $plan['fixes'][1] ?? '',
            'plan_food' => $plan['food'] ?? '',
            'plan_meals' => $plan['meals'] ?? '',
            'kcal' => $plan['kcal'] ?? null,
            'zenoo_product' => $zenoo['product'] ?? '',
            'zenoo_grams' => $zenoo['grams'] ?? null,
            'zenoo_cost_day' => $zenoo['cost'] ?? null,
            'dashboard_url' => $urls['dashboard'],
            'plan_url' => $urls['plan'],
        ] + $this->copy($locale, [
            'heading' => ['%dog%’s Bowl Score: %score%/100', ['%dog%' => $dog, '%score%' => $plan['score']]],
            'greeting' => $user->getFirstName() ? ['Hi %name%,', ['%name%' => $user->getFirstName()]] : 'Hi,',
            'intro' => ['Here is %dog%’s plan, so you have it to hand.', ['%dog%' => $dog]],
            'fixes_title' => 'What we’d do first',
            'plan_title' => 'The daily plan',
            'zenoo_title' => 'Fresher food with ZENOO',
            'zenoo_text' => $zenoo ? ['%product% %option%: about %grams% g a day (around €%cost% a day).', ['%product%' => $zenoo['product'], '%option%' => $this->translator->trans($zenoo['option_label'], [], null, $locale), '%grams%' => $zenoo['grams'], '%cost%' => number_format($zenoo['cost'], 2, \App\Locale::decimalSeparator($locale), ' ')]] : '',
            'button' => ['Open %dog%’s dashboard', ['%dog%' => $dog]],
            'note' => 'The coach in your dashboard can answer questions about the plan any time.',
        ]), $user);
    }

    /** Roadmap 04: the tips series (day 3, 7 and 14), only for pet parents who opted in. */
    public function sendSeriesEmail(User $user, string $dogName, int $day, array $content, string $buttonUrl, string $offUrl): void
    {
        $locale = $user->getLocale();
        $this->send($user->getEmail(), $content['subject'][0], $content['subject'][1], 'emails/series.html.twig', [
            'user' => $user, 'dogName' => $dogName, 'day' => $day, 'content' => $content, 'buttonUrl' => $buttonUrl, 'offUrl' => $offUrl,
        ], $locale, 'series', [
            'day' => $day,
            'dog_name' => $dogName,
            'button_url' => $buttonUrl,
            'off_url' => $offUrl,
        ] + $this->copy($locale, [
            'heading' => $content['heading'],
            'greeting' => $user->getFirstName() ? ['Hi %name%,', ['%name%' => $user->getFirstName()]] : 'Hi,',
            'text' => $content['text'],
            'button' => $content['button'],
            'off' => 'Don’t want these emails? Unsubscribe',
        ]), $user);
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
        ] + $this->copy($submission->getLocale(), [
            'heading' => ['%dog%’s Bowl Score: %score%/100', ['%dog%' => $submission->getDogName(), '%score%' => (int) ($submission->getData()['quiz_bowl_score'] ?? 0)]],
            'greeting' => $submission->getFirstName() ? ['Hi %name%,', ['%name%' => $submission->getFirstName()]] : 'Hi,',
            'intro' => ['Thanks for taking the ZENOO quiz. Confirm your email and we’ll create your free ZENOO account with %dog%’s profile, so you can come back to the score, the daily plan and lessons picked for %dog% any time.', ['%dog%' => $submission->getDogName()]],
            'button' => ['Confirm and open %dog%’s dashboard', ['%dog%' => $submission->getDogName()]],
            'note' => 'The link works for 7 days. If you didn’t take the quiz, you can ignore this email and nothing will be created.',
        ]), null, $submission->getFirstName());
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

    /**
     * The email's text in the recipient's language, as copy_* event properties: the same message
     * keys as the site's own templates (templates/emails/), plus the shared lines of the layout.
     *
     * @param array<string, string|array{string, array<string, string|int>}> $lines key => message, or [message, parameters]
     * @return array<string, string>
     */
    private function copy(string $locale, array $lines): array
    {
        $lines += [
            'link_hint' => 'Button not working? Copy this link:',
            'regards' => 'Warm regards,',
            'team' => 'ZENOO team',
            'tagline' => 'REAL FOOD. HEALTHY PET.',
            'footer' => 'You’re getting this email because of your ZENOO account at',
        ];
        $out = [];
        foreach ($lines as $key => $line) {
            [$message, $params] = \is_array($line) ? $line : [$line, []];
            $out['copy_' . $key] = $this->translator->trans($message, $params, null, $locale);
        }
        $out['copy_site'] = $this->host();
        return $out;
    }

    /** The site's address without https://, as the emails show it. */
    private function host(): string
    {
        return preg_replace('#^https?://#', '', rtrim($this->appUrl, '/'));
    }

    private function url(string $path): string
    {
        return rtrim($this->appUrl, '/') . $path;
    }
}
