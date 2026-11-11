<?php

namespace App\Command;

use App\Course\CourseVoices;
use App\Course\VoiceGenerator;
use App\Repository\CourseRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Makes the lesson voices with ElevenLabs from the terminal (the same as Course studio →
 * "Generate voices with ElevenLabs"): every Listen screen without audio, in every course.
 *
 *   php bin/console app:course:voice --dry-run        what it would make, and the characters it costs
 *   php bin/console app:course:voice --lang=uk        only the Ukrainian course
 *   php bin/console app:course:voice --lang=en --lesson=3 --force   redo lesson 3's voice
 */
#[AsCommand(name: 'app:course:voice', description: 'Make the Listen screens\' audio with ElevenLabs')]
final class CourseVoiceCommand extends Command
{
    public function __construct(
        private readonly CourseRepository $courses,
        private readonly CourseVoices $voices,
        private readonly VoiceGenerator $generator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('lang', null, InputOption::VALUE_REQUIRED, 'Only the course(s) in this language: en, lt, uk or pl')
            ->addOption('lesson', null, InputOption::VALUE_REQUIRED, 'Only this lesson number')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Also replace audio that exists already')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only list what would be made');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $lang = $input->getOption('lang');
        $lesson = $input->getOption('lesson') !== null ? (int) $input->getOption('lesson') : null;
        $dry = (bool) $input->getOption('dry-run');

        $jobs = [];
        foreach ($this->courses->findAllOrdered() as $course) {
            if ($lang && $course->getLocale() !== $lang) {
                continue;
            }
            foreach ($this->voices->screens($course, (bool) $input->getOption('force'), $lesson) as $screen) {
                $jobs[] = $screen + ['course' => $course];
            }
        }
        if (!$jobs) {
            $io->success('Nothing to make: every Listen screen has audio.');
            return Command::SUCCESS;
        }
        $io->writeln(sprintf('%d voices, %s characters (model %s).', \count($jobs), number_format(CourseVoices::characters($jobs)), $this->generator->model()));
        if ($dry) {
            foreach ($jobs as $j) {
                $io->writeln(sprintf('  [%s] %s (%d characters)', $j['course']->getLocale(), $j['lesson']->getTitle(), $j['chars']));
            }
            return Command::SUCCESS;
        }
        if (!$this->generator->isConfigured()) {
            $io->error('Set ELEVENLABS_API_KEY and ELEVENLABS_VOICE_ID first.');
            return Command::FAILURE;
        }

        $made = 0;
        foreach ($jobs as $j) {
            try {
                $url = $this->voices->generate($j['lesson'], $j['slide']);
                $made++;
                $io->writeln(sprintf('<info>✓</info> [%s] %s → %s', $j['course']->getLocale(), $j['lesson']->getTitle(), $url));
            } catch (\RuntimeException $e) {
                $io->error(sprintf('[%s] %s: %s', $j['course']->getLocale(), $j['lesson']->getTitle(), $e->getMessage()));
                $io->writeln(sprintf('%d made before this. Run the command again to continue.', $made));
                return Command::FAILURE;
            }
        }
        $io->success(sprintf('%d voices made.', $made));
        return Command::SUCCESS;
    }
}
