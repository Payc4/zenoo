<?php

namespace App\Command;

use App\Course\CourseDocumentParser;
use App\Course\LessonLayouts;
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
 * Loads the ZENOO Dog Wellness Course into the Course studio, one course per language:
 * English and Lithuanian from the course document (content/courses/zenoo-dog-wellness.md),
 * Ukrainian and Polish from their translations of the English lessons
 * (content/courses/zenoo-dog-wellness.uk.json / .pl.json, same slides as the English course).
 *
 * docker/docker-entrypoint.sh runs it with --missing on every deploy, so a language whose course
 * doesn't exist yet gets it automatically (a fresh database gets all four).
 * New courses are drafts (only admins see them) until someone ticks "Published" in the studio,
 * or runs this with --publish. Lessons edited in the studio are never overwritten unless you pass --force.
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
        'uk' => [
            'slug' => 'kurs-zdorovia-sobak',
            'title' => 'Курс ZENOO про здоров\'я собак',
            'description' => '30 коротких уроків від Аготи, засновниці ZENOO, для власників собак, які годують свіжим, домашнім чи сирим кормом або їх поєднанням. Приблизно 8 хвилин кожен.',
            'file' => 'content/courses/zenoo-dog-wellness.uk.json',
        ],
        'pl' => [
            'slug' => 'kurs-zdrowia-psa',
            'title' => 'Kurs zdrowia psa ZENOO',
            'description' => '30 krótkich lekcji od Agoty, założycielki ZENOO, dla opiekunów psów, którzy karmią świeżą, domową lub surową karmą albo ich połączeniem. Każda trwa około 8 minut.',
            'file' => 'content/courses/zenoo-dog-wellness.pl.json',
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
            ->addOption('lang', null, InputOption::VALUE_REQUIRED, 'Only this language: en, lt, uk or pl')
            ->addOption('publish', null, InputOption::VALUE_NONE, 'Publish the courses (visible to every logged-in user)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite lessons that already exist (learners keep their progress)')
            ->addOption('if-empty', null, InputOption::VALUE_NONE, 'Do nothing if any course exists already')
            ->addOption('missing', null, InputOption::VALUE_NONE, 'Only languages whose course doesn\'t exist yet (used on deploy)');
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

        $langs = $input->getOption('lang') ? [(string) $input->getOption('lang')] : array_keys(self::COURSES);
        if ($input->getOption('missing')) {
            $langs = array_values(array_filter($langs, fn (string $l) => isset(self::COURSES[$l]) && !$this->courses->findOneBy(['slug' => self::COURSES[$l]['slug']])));
            if (!$langs) {
                $io->writeln('Every language has its course already, nothing imported.');
                return Command::SUCCESS;
            }
        }

        $parsed = ['notes' => []];
        if (array_intersect($langs, ['en', 'lt'])) {
            try {
                $parsed = (new CourseDocumentParser())->parse((string) file_get_contents($path));
            } catch (\RuntimeException $e) {
                $io->error($e->getMessage());
                return Command::FAILURE;
            }
        }

        foreach ($langs as $lang) {
            if (isset(self::COURSES[$lang]['file'])) {
                try {
                    $parsed[$lang] = $this->translation($lang);
                } catch (\RuntimeException $e) {
                    $io->warning($e->getMessage());
                    continue;
                }
            }
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

    /**
     * A translated course: the lessons as the parser gives them (number, title, subtitle, minutes, slides).
     *
     * @return list<array>
     */
    private function translation(string $lang): array
    {
        $path = $this->projectDir . '/' . self::COURSES[$lang]['file'];
        $lessons = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (!\is_array($lessons) || !$lessons) {
            throw new \RuntimeException(sprintf('No lessons for "%s": %s is missing or not valid JSON.', $lang, self::COURSES[$lang]['file']));
        }
        foreach ($lessons as &$lesson) {
            $lesson['slides'] = LessonLayouts::sanitizeSlides($lesson['slides'] ?? []);
        }
        return $lessons;
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
                ->setPosition((int) array_search($lang, array_keys(self::COURSES), true));
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
