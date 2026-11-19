<?php

namespace App\Command;

use App\Entity\Product;
use App\Entity\Purchase;
use App\Entity\QuizSubmission;
use App\Entity\User;
use App\Service\EmailService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * For setting up the Klaviyo flows (EMAIL_DELIVERY=klaviyo): sends one SAMPLE event of every
 * site email ("ZENOO Welcome", "ZENOO Password Reset", …) to one email address, so each metric
 * exists in Klaviyo and can be picked as a flow trigger and used for previews.
 * The links in them are samples that open the site, not working reset or login links.
 *
 *   php bin/console app:klaviyo:sample-events you@example.com --lang=lt
 */
#[AsCommand(name: 'app:klaviyo:sample-events', description: 'Send one sample Klaviyo event of every site email to an address (for building the flows)')]
final class KlaviyoSampleEventsCommand extends Command
{
    public function __construct(
        private readonly EmailService $emails,
        #[Autowire('%app.url%')] private readonly string $appUrl,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Where the sample events go (a Klaviyo profile)')
            ->addOption('lang', null, InputOption::VALUE_REQUIRED, 'en, lt, uk or pl', 'en')
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'Comma-separated: welcome,order,reset,login,changed,quiz,followup,plan,series');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');
        $locale = \App\Locale::normalize($input->getOption('lang'));
        if ($this->emails->deliveryFor($locale) !== 'klaviyo') {
            $io->error('Site emails go out by SMTP here, not Klaviyo (EMAIL_DELIVERY / MAILER_DSN / KLAVIYO_PRIVATE_KEY).');
            return Command::FAILURE;
        }
        $only = $input->getOption('only') ? array_map('trim', explode(',', (string) $input->getOption('only'))) : array_keys(EmailService::EVENTS);
        $site = rtrim($this->appUrl, '/');

        $user = (new User())->setEmail($email)->setFirstName('Sample')->setLocale($locale);
        $product = (new Product())->setName('ZENOO sample product')->setSlug('sample')->setPrice('24.90');
        $purchase = (new Purchase())->setUser($user)->setProduct($product)->setAmount('24.90')->setCurrency('eur')->setTestMode(true);
        (new \ReflectionProperty(Purchase::class, 'id'))->setValue($purchase, 1000);
        $quiz = new QuizSubmission($email, 'Sample', $locale, ['quiz_dog_name' => 'Bella', 'quiz_bowl_score' => 52], bin2hex(random_bytes(8)), 'sample');

        $send = [
            'welcome' => fn () => $this->emails->sendWelcomeEmail($user),
            'order' => fn () => $this->emails->sendPurchaseConfirmation($purchase),
            'reset' => fn () => $this->emails->sendPasswordResetEmail($user, $site . '/forgot-password?sample=1'),
            'login' => fn () => $this->emails->sendMagicLoginLink($user, $site . '/login?sample=1', new \DateTimeImmutable('+10 minutes')),
            'changed' => fn () => $this->emails->sendPasswordChangedNotice($user),
            'quiz' => fn () => $this->emails->sendQuizConfirmation($quiz, $site . '/quiz/?sample=1'),
            'plan' => fn () => $this->emails->sendPlanEmail($user, ['dog' => 'Bella', 'score' => 52, 'fixes' => ['Weigh her food for a week.', 'Add a fresher topper.'], 'food' => 'Dry food: about 290 g a day', 'meals' => '2 meals a day', 'kcal' => 1130, 'zenoo' => ['product' => 'ZENOO RAW', 'option_label' => 'as a topper', 'grams' => 155, 'cost' => 3.9]], ['dashboard' => $site . '/dashboard', 'plan' => $site . '/dashboard#zenoo-h']),
            'series' => fn () => $this->emails->sendSeriesEmail($user, 'Bella', 7, ['subject' => ['One week with %dog%’s plan', ['%dog%' => 'Bella']], 'heading' => ['One week with %dog%’s plan', ['%dog%' => 'Bella']], 'text' => ['How is it going? Weigh %dog% and note the stool and any itching in the trackers on your dashboard. It takes a minute, and the coach uses it to keep the advice right.', ['%dog%' => 'Bella']], 'button' => 'Open the trackers'], $site . '/dashboard#trackers-h', $site . '/emails/off?sample=1'),
            'followup' => fn () => $this->emails->sendCoachFollowUp($user, 'Bella', 'Two weeks in: what does Bella weigh now?', $site . '/chat', $site . '/coach-emails/off?sample=1'),
        ];
        foreach ($only as $key) {
            if (!isset($send[$key])) {
                $io->warning("Unknown email \"$key\".");
                continue;
            }
            try {
                $send[$key]();
                $io->writeln('Sent "' . EmailService::EVENTS[$key] . '"');
            } catch (\Throwable $e) {
                $io->error(EmailService::EVENTS[$key] . ': ' . $e->getMessage());
                return Command::FAILURE;
            }
        }
        $io->success("Sample events sent to $email ($locale). They appear under Klaviyo → Analytics → Metrics within a minute.");
        return Command::SUCCESS;
    }
}
