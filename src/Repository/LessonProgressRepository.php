<?php

namespace App\Repository;

use App\Entity\Course;
use App\Entity\Lesson;
use App\Entity\LessonProgress;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<LessonProgress> */
class LessonProgressRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LessonProgress::class);
    }

    public function findOneFor(User $user, Lesson $lesson): ?LessonProgress
    {
        return $this->findOneBy(['user' => $user, 'lesson' => $lesson]);
    }

    /** @return array<int, LessonProgress> keyed by lesson id */
    public function findForCourse(User $user, Course $course): array
    {
        $rows = $this->createQueryBuilder('p')
            ->join('p.lesson', 'l')
            ->andWhere('p.user = :u')->andWhere('l.course = :c')
            ->setParameter('u', $user)->setParameter('c', $course)
            ->getQuery()->getResult();
        $out = [];
        foreach ($rows as $p) {
            $out[$p->getLesson()->getId()] = $p;
        }
        return $out;
    }

    /**
     * Consecutive days (ending today, or yesterday if nothing yet today) on
     * which the user finished at least one lesson.
     */
    public function getStreak(User $user): int
    {
        $rows = $this->createQueryBuilder('p')
            ->select('p.completedAt')
            ->andWhere('p.user = :u')->andWhere('p.completedAt IS NOT NULL')
            ->setParameter('u', $user)
            ->getQuery()->getArrayResult();

        $days = [];
        foreach ($rows as $r) {
            $days[$r['completedAt']->format('Y-m-d')] = true;
        }
        if (!$days) {
            return 0;
        }

        $day = new \DateTimeImmutable('today');
        if (!isset($days[$day->format('Y-m-d')])) {
            $day = $day->modify('-1 day');
        }
        $streak = 0;
        while (isset($days[$day->format('Y-m-d')])) {
            $streak++;
            $day = $day->modify('-1 day');
        }
        return $streak;
    }

    /** Removes everything a learner did in the courses (used when they delete their account). */
    public function deleteAllForUser(User $user): void
    {
        $this->createQueryBuilder('p')->delete()->where('p.user = :u')->setParameter('u', $user)->getQuery()->execute();
    }

    /**
     * What the chat coach should know about the learner's course work: finished lessons
     * and their latest written answers (newest first).
     *
     * @return array{completed: list<string>, answers: list<array{lesson: string, task: string, answer: string}>}
     */
    public function summaryForCoach(User $user, int $maxAnswers = 8): array
    {
        $rows = $this->createQueryBuilder('p')
            ->join('p.lesson', 'l')->addSelect('l')
            ->join('l.course', 'c')->addSelect('c')
            ->andWhere('p.user = :u')->setParameter('u', $user)
            ->orderBy('p.updatedAt', 'DESC')
            ->getQuery()->getResult();

        $completed = [];
        $answers = [];
        foreach ($rows as $p) {
            $lesson = $p->getLesson();
            if ($p->isCompleted()) {
                $completed[] = $lesson->getTitle();
            }
            foreach ($lesson->getSlides() as $slide) {
                if ($slide['layout'] !== 'write' || \count($answers) >= $maxAnswers) {
                    continue;
                }
                foreach ($p->getAnswer($slide['id'])['text'] ?? [] as $i => $text) {
                    if (trim((string) $text) !== '') {
                        $answers[] = [
                            'lesson' => $lesson->getTitle(),
                            'task' => (string) ($slide['fields']['prompts'][$i]['instructions'] ?? $slide['fields']['headline'] ?? ''),
                            'answer' => mb_substr(trim((string) $text), 0, 600),
                        ];
                    }
                }
            }
        }
        return ['completed' => $completed, 'answers' => \array_slice($answers, 0, $maxAnswers)];
    }
}
