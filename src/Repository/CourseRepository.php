<?php

namespace App\Repository;

use App\Entity\Course;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Course> */
class CourseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Course::class);
    }

    /** @return Course[] */
    public function findAllOrdered(bool $publishedOnly = false): array
    {
        $qb = $this->createQueryBuilder('c')->orderBy('c.position', 'ASC')->addOrderBy('c.id', 'ASC');
        if ($publishedOnly) {
            $qb->andWhere('c.isPublished = true');
        }
        return $qb->getQuery()->getResult();
    }
}
