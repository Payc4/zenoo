<?php

namespace App\Command;

use App\Chat\SafetyCheck;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * Runs the safety test set (config/chatbot/safety_eval.yaml, ~40 prompts in four languages) through
 * the real safety check: emergencies must get the emergency reply, "can I give X?" and past illnesses
 * must not, and health facts must be picked out to save. Run before each release (test report F5).
 * Needs ANTHROPIC_API_KEY; uses the fast model, so it's cheap.
 */
#[AsCommand(name: 'app:coach:safety-eval', description: 'Run the coach safety test set (emergencies vs questions, in 4 languages)')]
final class SafetyEvalCommand extends Command
{
    public function __construct(
        private readonly SafetyCheck $safety,
        #[Autowire('%kernel.project_dir%/config/chatbot/safety_eval.yaml')] private readonly string $file,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $cases = Yaml::parseFile($this->file)['cases'] ?? [];
        $rows = [];
        $failed = 0;
        foreach ($cases as $i => $case) {
            $r = $this->safety->triage((string) $case['message'], 'Max');
            $flag = $r['flag'] ?? 'none';
            $problems = [];
            if ($flag !== $case['flag']) {
                $problems[] = "flag $flag, expected {$case['flag']}";
            }
            if (isset($case['fact']) && !\in_array($case['fact'], array_column($r['facts'], 'kind'), true)) {
                $problems[] = "no {$case['fact']} fact saved";
            }
            $failed += $problems ? 1 : 0;
            $rows[] = [$i + 1, $case['lang'], mb_strimwidth((string) $case['message'], 0, 60, '…'), $r['situation'], $flag, $problems ? '✗ ' . implode('; ', $problems) : '✓'];
        }
        $io->table(['#', 'Lang', 'Message', 'Situation', 'Flag', 'Result'], $rows);
        if ($failed) {
            $io->error(sprintf('%d of %d cases failed.', $failed, \count($cases)));
            return Command::FAILURE;
        }
        $io->success(sprintf('All %d cases passed.', \count($cases)));
        return Command::SUCCESS;
    }
}
