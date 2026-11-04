<?php

namespace App\Command;

use App\Course\CourseDocumentParser;
use App\Entity\Course;
use App\Entity\Lesson;
use App\Repository\CourseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Loads the ZENOO Dog Wellness Course (English + Lithuanian) from the course document
 * into the Course studio. Run once after deploying; docker/entrypoint.sh runs it with
 * --if-empty, so a fresh database gets the lessons automatically.
 *
 * Both courses are created as drafts (only admins see them) until someone ticks
 * "Published" in the studio, or runs this with --publish.
 * Lessons edited in the studio are never overwritten unless you pass --force.
 */
#[AsCommand(name: 'app:course:import', description: 'Import the ZENOO Dog Wellness Course lessons from the course document')]
final class ImportCourseCommand extends Command
{
    public const COURSES = [
        'en' => [
            'slug' => 'dog-wellness',
            'title' => 'ZENOO Dog Wellness Course',
            'description' => '30 short lessons from Agota, founder of ZENOO, for pet parents who feed fresh, home-cooked or raw food, or a mix. About 8 minutes each.',
        ],
        'lt' => [
            'slug' => 'sunu-sveikatos-kursas',
            'title' => 'ZENOO šunų sveikatos kursas',
            'description' => '30 trumpų Agotos, ZENOO įkūrėjos, pamokų šeimininkams, kurie šeria šviežiu, namuose gamintu ar žaliu maistu arba jų deriniu. Kiekviena – apie 8 minutes.',
        ],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CourseRepository $courses,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::OPTIONAL, 'The course document (Markdown)', 'content/courses/zenoo-dog-wellness.md')
            ->addOption('lang', null, InputOption::VALUE_REQUIRED, 'Only this language: en or lt')
            ->addOption('publish', null, InputOption::VALUE_NONE, 'Publish the courses (visible to every logged-in user)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite lessons that already exist (learners keep their progress)')
            ->addOption('if-empty', null, InputOption::VALUE_NONE, 'Do nothing if any course exists already (used on deploy)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ($input->getOption('if-empty') && $this->courses->count([]) > 0) {
            $io->writeln('Courses already exist, nothing imported.');
            return Command::SUCCESS;
        }

        $file = (string) $input->getArgument('file');
        $path = str_starts_with($file, '/') ? $file : $this->projectDir . '/' . $file;
        if (!is_file($path)) {
            $io->error("Course document not found: $path");
            return Command::FAILURE;
        }

        try {
            $parsed = (new CourseDocumentParser())->parse((string) file_get_contents($path));
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $langs = $input->getOption('lang') ? [(string) $input->getOption('lang')] : ['en', 'lt'];
        foreach ($langs as $lang) {
            if (!isset(self::COURSES[$lang]) || empty($parsed[$lang])) {
                $io->warning("No lessons for language \"$lang\".");
                continue;
            }
            [$created, $updated, $kept] = $this->import($lang, $parsed[$lang], (bool) $input->getOption('publish'), (bool) $input->getOption('force'));
            $io->writeln(sprintf('<info>%s</info>: %d lessons created, %d updated, %d left as they were.', self::COURSES[$lang]['title'], $created, $updated, $kept));
        }

        if ($parsed['notes']) {
            $io->section('Left out of the published text (notes for the team)');
            $io->listing($parsed['notes']);
        }
        $io->success($input->getOption('publish') ? 'Done. The courses are published.' : 'Done. The courses are drafts: publish them in Admin → Course studio after the vet review.');
        return Command::SUCCESS;
    }

    /** @return array{int, int, int} created, updated, kept */
    private function import(string $lang, array $lessons, bool $publish, bool $force): array
    {
        $meta = self::COURSES[$lang];
        $course = $this->courses->findOneBy(['slug' => $meta['slug']]);
        if (!$course) {
            $course = (new Course())
                ->setTitle($meta['title'])
                ->setSlug($meta['slug'])
                ->setDescription($meta['description'])
                ->setLocale($lang)
                ->setIsFree(true)
                ->setPosition($lang === 'en' ? 0 : 1);
            $this->em->persist($course);
        }
        if ($publish) {
            $course->setIsPublished(true);
        }

        $existing = [];
        foreach ($course->getLessons() as $l) {
            $existing[$l->getPosition()] = $l;
        }

        $created = $updated = $kept = 0;
        foreach ($lessons as $data) {
            $position = $data['number'] - 1;
            $lesson = $existing[$position] ?? null;
            if ($lesson && !$force) {
                $kept++;
                continue;
            }
            if (!$lesson) {
                $lesson = (new Lesson())->setPosition($position)->setIsPublished(true);
                $course->addLesson($lesson);
                $this->em->persist($lesson);
                $created++;
            } else {
                $updated++;
            }
            $lesson
                ->setTitle(mb_substr($data['title'], 0, 255))
                ->setSubtitle($data['subtitle'] ? mb_substr($data['subtitle'], 0, 255) : null)
                ->setDurationMinutes($data['minutes'])
                ->setSlides($data['slides']);
        }
        $this->em->flush();

        return [$created, $updated, $kept];
    }
}
