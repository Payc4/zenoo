<?php

namespace App\Command;

use App\Entity\DogProfile;
use App\Quiz\ScoreInputs;
use App\Quiz\ScoreRecalculator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Works every dog's Bowl Score and plan out again under the current rules (run on each deploy by
 * docker/docker-entrypoint.sh). Dogs saved before the quiz kept its scoring inputs get them
 * rebuilt from their saved result first. A dog whose score or plan changes gets the notice on
 * Today ("worked out again with ZENOO's updated rules"); the others are untouched.
 */
#[AsCommand(name: 'app:dogs:rescore', description: 'Re-score every dog under the current rules (rebuilds old results first)')]
final class RescoreDogsCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly ScoreRecalculator $rescorer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would change, save nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dry = (bool) $input->getOption('dry-run');
        $now = new \DateTimeImmutable();
        $counts = ['dogs' => 0, 'rebuilt' => 0, 'changed' => 0, 'skipped' => 0];
        $ids = $this->em->createQuery('SELECT d.id FROM App\Entity\DogProfile d ORDER BY d.id')->getSingleColumnResult();
        foreach (array_chunk($ids, 50) as $chunk) {
            foreach ($this->em->getRepository(DogProfile::class)->findBy(['id' => $chunk]) as $dog) {
                $counts['dogs']++;
                if (ScoreInputs::fromDog($dog) === null) {
                    $rebuilt = ScoreInputs::rebuild($dog);
                    if ($rebuilt === null) {
                        $counts['skipped']++;
                        $output->writeln(sprintf('  #%d %s: not enough saved to re-score', $dog->getId(), $dog->getName()), OutputInterface::VERBOSITY_VERBOSE);
                        continue;
                    }
                    $dog->setScoreInputs($rebuilt + ['_rebuilt' => true]);
                    $counts['rebuilt']++;
                }
                $before = [$dog->getBowlScore(), $dog->get('plan_food')];
                if ($this->rescorer->recalculate($dog, 'rules', $now) !== null) {
                    $counts['changed']++;
                    $output->writeln(sprintf('  #%d %s: %d → %d', $dog->getId(), $dog->getName(), $before[0], $dog->getBowlScore()), OutputInterface::VERBOSITY_VERBOSE);
                }
            }
            if (!$dry) {
                $this->em->flush();
            }
            $this->em->clear();
        }
        $output->writeln(sprintf('Re-scored %d dogs: %d rebuilt from old results, %d changed, %d without enough saved.%s',
            $counts['dogs'], $counts['rebuilt'], $counts['changed'], $counts['skipped'], $dry ? ' (dry run: nothing saved)' : ''));
        return Command::SUCCESS;
    }
}
