<?php

namespace App\Command;

use App\Entity\DogProfile;
use App\Repository\ChatConversationRepository;
use App\Service\EmailService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\UriSigner;

/**
 * Emails the check-ins the coach planned (schedule_followup) once they're due. Run it once a day
 * (a Coolify scheduled task: php bin/console app:coach:followups). The coach asks the same
 * question when they next open the chat. Pet parents who chatted in the last day are skipped:
 * they'll get it in the chat.
 */
#[AsCommand(name: 'app:coach:followups', description: 'Email the coach check-ins that are due')]
final class CoachFollowUpsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ChatConversationRepository $conversations,
        private readonly EmailService $emails,
        private readonly UriSigner $signer,
        private readonly LoggerInterface $logger,
        #[Autowire('%app.url%')] private readonly string $appUrl,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would be sent');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = new \DateTimeImmutable();
        $dry = (bool) $input->getOption('dry-run');
        $base = rtrim($this->appUrl, '/');
        $sent = 0;

        /** @var DogProfile $dog */
        foreach ($this->em->getRepository(DogProfile::class)->findAll() as $dog) {
            $due = $dog->dueFollowUp($now);
            $user = $dog->getUser();
            if ($due === null || $due['sent'] !== null || !$user->wantsCoachEmails()) {
                continue;
            }
            $last = $this->conversations->findForUser($user)?->getMessages()->last() ?: null;
            if ($last && $last->getCreatedAt() > $now->modify('-1 day')) {
                continue;
            }
            $io->writeln(sprintf('%s (%s): %s', $user->getEmail(), $dog->getName(), $due['question']));
            if ($dry) {
                continue;
            }
            try {
                $this->emails->sendCoachFollowUp(
                    $user,
                    $dog->getName(),
                    $due['question'],
                    $base . '/chat',
                    $this->signer->sign($base . '/coach-emails/off?u=' . $user->getId()),
                );
                $dog->markFollowUp($due['id'], $now);
                $this->em->flush();
                ++$sent;
            } catch (\Throwable $e) {
                $this->logger->error('Coach check-in email failed', ['exception' => $e, 'user' => $user->getId()]);
                $io->warning('Failed for ' . $user->getEmail() . ': ' . $e->getMessage());
            }
        }

        $io->success($dry ? 'Dry run done.' : "Sent $sent check-in email(s).");
        return Command::SUCCESS;
    }
}
