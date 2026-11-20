<?php

namespace App\Service;

use App\Catalogue\PlanBuilder;
use App\Entity\DogProfile;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\UriSigner;

/**
 * Roadmap 04, emails in every language: the site sends them itself (through Klaviyo where that
 * language has it, else SMTP), so Lithuanian, Ukrainian and Polish pet parents get the same as
 * English ones. By default only for SITE_EMAIL_SERIES_LOCALES (lt, uk, pl): English is covered by
 * the Klaviyo quiz flow.
 *
 * - The plan email: right after the account is made, to everyone (they asked for the plan).
 * - The series: day 3 (a lesson), day 7 (one week in: weigh and log), day 14 (the ZENOO plan),
 *   only with the quiz's tips-and-offers tick (User::wantsMarketingEmails()). Sent by
 *   app:emails:series once a day; each step once (DogProfile data "email_series").
 */
final class PlanEmails
{
    public const SERIES_DAYS = [3, 7, 14];

    public function __construct(
        private readonly EmailService $emails,
        private readonly PlanBuilder $plans,
        private readonly EntityManagerInterface $em,
        private readonly UriSigner $signer,
        private readonly LoggerInterface $logger,
        #[Autowire('%app.url%')] private readonly string $appUrl,
        #[Autowire('%env(default:email_series_locales_default:SITE_EMAIL_SERIES_LOCALES)%')] private readonly string $locales,
    ) {
    }

    public function coversLocale(string $locale): bool
    {
        return \in_array($locale, array_map('trim', explode(',', strtolower($this->locales))), true);
    }

    /** After the account is made (quiz confirmation). Never throws: a failed email mustn't stop the sign-up. */
    public function sendPlan(User $user, DogProfile $dog): void
    {
        if (!$this->coversLocale($user->getLocale()) || isset($this->sent($dog)['0'])) {
            return;
        }
        try {
            $this->emails->sendPlanEmail($user, $this->planContent($dog), [
                'dashboard' => $this->url('/dashboard'),
                'plan' => $this->url('/dashboard/dog#zenoo-h'),
            ]);
            $this->markSent($dog, 0);
        } catch (\Throwable $e) {
            $this->logger->error('Plan email failed', ['exception' => $e, 'user' => $user->getId()]);
        }
    }

    /** The series steps that are due today. Returns how many were sent. */
    public function sendDue(\DateTimeImmutable $now, bool $dry = false, ?callable $report = null): int
    {
        $count = 0;
        /** @var DogProfile $dog */
        foreach ($this->em->getRepository(DogProfile::class)->findAll() as $dog) {
            $user = $dog->getUser();
            if (!$user->wantsMarketingEmails() || !$this->coversLocale($user->getLocale())) {
                continue;
            }
            $age = (int) floor(($now->getTimestamp() - $dog->getCreatedAt()->getTimestamp()) / 86400);
            $sent = $this->sent($dog);
            // The latest step that's due and not sent yet (a late cron run doesn't send a pile).
            $due = null;
            foreach (self::SERIES_DAYS as $day) {
                if ($age >= $day && !isset($sent[(string) $day])) {
                    $due = $day;
                }
            }
            if ($due === null || $age > $due + 3) {
                if ($due !== null) {
                    $this->markSent($dog, $due); // too late for this step: skip it
                }
                continue;
            }
            $report && $report(sprintf('%s (%s): day %d', $user->getEmail(), $dog->getName(), $due));
            if ($dry) {
                continue;
            }
            try {
                [$content, $path] = $this->seriesContent($dog, $due);
                $this->emails->sendSeriesEmail($user, $dog->getName(), $due, $content, $this->url($path), $this->offUrl($user));
                $this->markSent($dog, $due);
                ++$count;
            } catch (\Throwable $e) {
                $this->logger->error('Series email failed', ['exception' => $e, 'user' => $user->getId(), 'day' => $due]);
            }
        }
        $this->em->flush();
        return $count;
    }

    public function offUrl(User $user): string
    {
        return $this->signer->sign($this->url('/emails/off?u=' . $user->getId()));
    }

    /** What the plan email shows: all already in the pet parent's language (from the quiz) or numbers. */
    public function planContent(DogProfile $dog): array
    {
        $plan = $this->plans->forDog($dog);
        $zenoo = null;
        if ($plan) {
            $o = $plan['options'][$plan['recommended']];
            $zenoo = [
                'product' => $plan['product']->getTitle(),
                'option_label' => ['topper' => 'as a topper', 'half' => 'as half the bowl', 'full' => 'as the whole bowl'][$plan['recommended']],
                'grams' => $o['grams_per_day'],
                'cost' => $o['cost_per_day'],
            ];
        }
        return [
            'dog' => $dog->getName(),
            'score' => $dog->getBowlScore(),
            'fixes' => array_values(array_filter([(string) $dog->get('fix_1_text'), (string) $dog->get('fix_2_text'), (string) $dog->get('fix_3_text')])),
            'food' => (string) $dog->get('plan_food'),
            'meals' => (string) $dog->get('plan_meals'),
            'kcal' => $dog->get('kcal_target') !== null ? (int) $dog->get('kcal_target') : null,
            'zenoo' => $zenoo,
        ];
    }

    /** @return array{array, string} the email's lines (translation keys and params), the button's path */
    private function seriesContent(DogProfile $dog, int $day): array
    {
        $d = ['%dog%' => $dog->getName()];
        return match ($day) {
            3 => [[
                'subject' => ['A 5-minute lesson for %dog%', $d],
                'heading' => ['A 5-minute lesson for %dog%', $d],
                'text' => ['The ZENOO course has 30 short lessons on feeding, with a voice to listen to. Start with the first one: it explains what’s really in the bowl and why it matters for %dog%.', $d],
                'button' => 'Start the first lesson',
            ], '/courses/lesson/1'],
            7 => [[
                'subject' => ['One week with %dog%’s plan', $d],
                'heading' => ['One week with %dog%’s plan', $d],
                'text' => ['How is it going? Weigh %dog% and note the stool and any itching in the trackers on your dashboard. It takes a minute, and the coach uses it to keep the advice right.', $d],
                'button' => 'Open the trackers',
            ], '/dashboard#log'],
            default => $this->plans->forDog($dog) ? [[
                'subject' => ['Ready to try fresher food for %dog%?', $d],
                'heading' => ['Fresher food for %dog%', $d],
                'text' => ['We worked out how ZENOO would fit %dog%: as a topper, half the bowl or the whole bowl, with the grams and cost a day. A 2-week starter is the easiest way to see the difference.', $d],
                'button' => ['See %dog%’s ZENOO plan', $d],
            ], '/dashboard/dog#zenoo-h'] : [[
                'subject' => ['Two weeks with %dog%’s plan', $d],
                'heading' => ['Two weeks with %dog%’s plan', $d],
                'text' => ['Any questions about the food, portions or something you noticed? The coach knows %dog%’s profile and answers in a minute.', $d],
                'button' => 'Ask the coach',
            ], '/chat'],
        };
    }

    private function sent(DogProfile $dog): array
    {
        return $dog->getEmailSeries();
    }

    private function markSent(DogProfile $dog, int $day): void
    {
        $dog->markEmailSent($day, new \DateTimeImmutable());
    }

    private function url(string $path): string
    {
        return rtrim($this->appUrl, '/') . $path;
    }
}
