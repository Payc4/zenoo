<?php

namespace App\Repository;

use App\Entity\CatalogueProduct;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CatalogueProduct> */
class CatalogueProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CatalogueProduct::class);
    }

    /** @return list<CatalogueProduct> products the plan and the coach may use */
    public function findUsable(): array
    {
        return array_values(array_filter($this->findBy(['active' => true, 'hidden' => false], ['title' => 'ASC']), fn (CatalogueProduct $p) => $p->isUsable()));
    }
}
