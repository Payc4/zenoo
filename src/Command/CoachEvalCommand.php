<?php

namespace App\Command;

use App\Chat\ChatAgent;
use App\Chat\ClaudeClient;
use App\Chat\SafetyCheck;
use App\Entity\ChatConversation;
use App\Entity\ChatMessage;
use App\Entity\DogProfile;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * The coach's fixed test set (config/chatbot/eval.yaml) against the real model: the safety check,
 * then the coach, for each case in a fresh chat with a temporary test dog. Exits 1 if a case fails.
 * Costs a little (one short call per case, plus a coach reply when there's no red flag).
 */
#[AsCommand(name: 'app:coach:eval', description: 'Run the coach\'s fixed test questions against the real model')]
final class CoachEvalCommand extends Command
{
    private const LETTERS = ['lt' => '/[ąčęėįšųūž]/iu', 'uk' => '/[іїєґ]/iu', 'pl' => '/[ąćęłńóśźż]/iu'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ChatAgent $agent,
        private readonly SafetyCheck $safety,
        private readonly ClaudeClient $claude,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('only', null, InputOption::VALUE_REQUIRED, 'Run the cases whose name contains this text')
            ->addOption('show', null, InputOption::VALUE_NONE, 'Print every reply');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->claude->isConfigured()) {
            $io->error('ANTHROPIC_API_KEY isn\'t set.');
            return Command::FAILURE;
        }
        $cases = Yaml::parseFile($this->projectDir . '/config/chatbot/eval.yaml')['cases'] ?? [];
        if ($only = $input->getOption('only')) {
            $cases = array_values(array_filter($cases, fn ($c) => stripos($c['name'], $only) !== false));
        }

        $user = (new User())->setEmail('coach-eval-' . bin2hex(random_bytes(4)) . '@example.invalid')->setFirstName('Maria')
            ->setPassword('!eval-account-no-login')->setHasPassword(false); // not a usable hash: nobody can log in
        $this->em->persist($user);
        $dog = (new DogProfile($user))->fillFromQuiz([
            'quiz_dog_name' => 'Bella', 'quiz_breed' => 'Labrador Retriever', 'quiz_age_years' => 4.5, 'quiz_life_stage' => 'adult',
            'quiz_weight_kg' => 31, 'quiz_bcs' => 7, 'quiz_activity_band' => 3, 'quiz_neutered' => 'yes', 'quiz_meals_per_day' => '2',
            'quiz_treats' => 'few', 'quiz_signs' => ['itching'], 'quiz_food_main' => 'dry', 'quiz_bowl_score' => 44, 'quiz_score_band' => 'look',
            'quiz_kcal_target' => 1134, 'quiz_plan_food' => 'Dry food: about 290 g a day', 'quiz_plan_meals' => '2 meals a day',
        ], 'en');
        $dog->setFood('Happy Hound', 'Adult Chicken & Rice', 'dry', '', 'chat', new \DateTimeImmutable());
        $this->em->persist($dog);
        $this->em->flush();

        $rows = [];
        $failed = 0;
        try {
            foreach ($cases as $case) {
                try {
                    [$ok, $why, $reply] = $this->runCase($case, $user, $dog);
                } catch (\Throwable $e) {
                    [$ok, $why, $reply] = [false, 'error: ' . $e->getMessage(), ''];
                }
                $failed += $ok ? 0 : 1;
                $rows[] = [$ok ? '<info>pass</info>' : '<error>FAIL</error>', $case['name'], $why];
                if ($input->getOption('show') || !$ok) {
                    $io->section($case['name']);
                    $io->writeln($reply);
                }
            }
        } finally {
            foreach ($this->em->getRepository(ChatConversation::class)->findBy(['user' => $user]) as $c) {
                $this->em->remove($c);
            }
            $this->em->remove($dog);
            $this->em->remove($user);
            $this->em->flush();
        }

        $io->table(['', 'Case', 'Result'], $rows);
        if ($failed) {
            $io->error("$failed of " . \count($cases) . ' cases failed.');
            return Command::FAILURE;
        }
        $io->success('All ' . \count($cases) . ' cases passed.');
        return Command::SUCCESS;
    }

    /** @return array{bool, string, string} passed?, why, the reply */
    private function runCase(array $case, User $user, DogProfile $dog): array
    {
        $user->setLocale($case['locale'] ?? 'en');
        $expected = isset($case['flag']) ? (array) $case['flag'] : [];

        $flag = $this->safety->check($case['message'], 'Bella');
        if ($flag !== null) {
            $reply = SafetyCheck::reply($flag, $case['locale'] ?? 'en', 'Bella');
            return \in_array($flag, $expected, true)
                ? [true, "safety check: $flag", $reply]
                : [false, $expected ? "safety check said $flag, expected " . implode('/', $expected) : "safety check said $flag, but this isn't an emergency", $reply];
        }
        if ($expected) {
            return [false, 'safety check missed it (expected ' . implode('/', $expected) . ')', ''];
        }

        // A fresh chat for each case.
        foreach ($this->em->getRepository(ChatConversation::class)->findBy(['user' => $user]) as $c) {
            $this->em->remove($c);
        }
        $this->em->flush();
        $conversation = new ChatConversation($user);
        $this->em->persist($conversation);
        $this->em->persist(new ChatMessage($conversation, ChatMessage::ROLE_USER, $case['message']));
        $this->em->flush();
        $this->em->refresh($conversation);

        $result = $this->agent->reply($conversation, static function (): void {});
        $text = $result['text'];
        $checks = [];
        if ($text === '' && !$result['questions']) {
            return [false, 'empty reply', ''];
        }
        if (($result['stop_reason'] ?? null) === 'max_tokens') {
            $checks[] = 'cut off';
        }
        foreach ((array) ($case['uses'] ?? []) as $tool) {
            if (!\in_array($tool, $result['tools'], true)) {
                $checks[] = "didn't use $tool";
            }
        }
        if ($any = (array) ($case['contains_any'] ?? [])) {
            if (!array_filter($any, fn ($w) => mb_stripos($text, $w) !== false)) {
                $checks[] = 'mentions none of: ' . implode(', ', $any);
            }
        }
        if (isset($case['not_matching']) && preg_match('~' . $case['not_matching'] . '~u', $text)) {
            $checks[] = 'matches ' . $case['not_matching'];
        }
        if (isset($case['max_chars']) && mb_strlen($text) > $case['max_chars']) {
            $checks[] = 'too long (' . mb_strlen($text) . ' chars)';
        }
        $locale = $case['locale'] ?? 'en';
        if (isset(self::LETTERS[$locale]) && !preg_match(self::LETTERS[$locale], $text)) {
            $checks[] = "not in $locale";
        }
        if ($locale === 'en' && preg_match('/[ąčęėįšųūžіїєґ]/iu', $text)) {
            $checks[] = 'not in English';
        }
        $tools = $result['tools'] ? ' (tools: ' . implode(', ', array_unique($result['tools'])) . ')' : '';
        return [!$checks, $checks ? implode('; ', $checks) : 'answered' . $tools, $text];
    }
}
