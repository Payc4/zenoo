<?php

namespace App\Command;

use App\Service\PlanEmails;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** The plan email series (day 3, 7, 14) for pet parents who opted in. Daily Coolify task. */
#[AsCommand(name: 'app:emails:series', description: 'Send the plan email series steps that are due (opted-in pet parents)')]
final class EmailSeriesCommand extends Command
{
    public function __construct(private readonly PlanEmails $planEmails)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would be sent');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dry = (bool) $input->getOption('dry-run');
        $n = $this->planEmails->sendDue(new \DateTimeImmutable(), $dry, fn (string $line) => $io->writeln($line));
        $io->success($dry ? 'Dry run done.' : "Sent $n series email(s).");
        return Command::SUCCESS;
    }
}
