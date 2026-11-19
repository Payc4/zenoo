<?php

namespace App\Repository;

use App\Entity\Product;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Product> */
class ProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    /** @return list<Product> the shop's products (the lesson unlock is sold on the courses page) */
    public function findActive(): array
    {
        return $this->findBy(['active' => true, 'unlocksLessons' => false], ['createdAt' => 'ASC', 'id' => 'ASC']);
    }

    /** The product that opens the locked lessons, if one is set up and on sale. */
    public function findLessonUnlock(): ?Product
    {
        return $this->findOneBy(['unlocksLessons' => true, 'active' => true], ['id' => 'ASC']);
    }

    public function findActiveBySlug(string $slug): ?Product
    {
        return $this->findOneBy(['slug' => $slug, 'active' => true]);
    }
}
