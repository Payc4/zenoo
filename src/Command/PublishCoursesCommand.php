<?php

namespace App\Command;

use App\Repository\CourseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Publishes the Dog Wellness Course in the given languages, so members see the lessons in the
 * language they picked (an unpublished translation falls back to English). Run on every deploy with
 * COURSE_PUBLISH_LOCALES (default en,lt,uk,pl); a language left out of that list is not unpublished.
 */
#[AsCommand(name: 'app:course:publish', description: 'Publish the Dog Wellness Course in these languages')]
final class PublishCoursesCommand extends Command
{
    public function __construct(private readonly CourseRepository $courses, private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('locales', InputArgument::REQUIRED, 'Comma-separated languages, e.g. en,lt,uk,pl');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $published = [];
        foreach (array_filter(array_map('trim', explode(',', (string) $input->getArgument('locales')))) as $locale) {
            $slug = ImportCourseCommand::COURSES[$locale]['slug'] ?? null;
            $course = $slug ? $this->courses->findOneBy(['slug' => $slug]) : null;
            if ($course && !$course->isPublished()) {
                $course->setIsPublished(true);
                $published[] = $locale;
            }
        }
        $this->em->flush();
        $output->writeln($published ? 'Published the course in: ' . implode(', ', $published) . '.' : 'Courses: nothing new to publish.');

        return Command::SUCCESS;
    }
}
