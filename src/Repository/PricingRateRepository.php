<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PricingRate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PricingRate>
 */
class PricingRateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PricingRate::class);
    }

    /**
     * @param list<string> $zones
     *
     * @return list<PricingRate>
     */
    public function findByZones(array $zones): array
    {
        if ($zones === []) {
            return [];
        }

        /** @var list<PricingRate> $rows */
        $rows = $this->createQueryBuilder('p')
            ->andWhere('p.zone IN (:zones)')
            ->setParameter('zones', $zones)
            ->orderBy('p.categoryLabel', 'ASC')
            ->addOrderBy('p.subcategory', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function findOneByCategorySubcategoryZone(
        string $categoryLabel,
        string $subcategory,
        string $zone,
    ): ?PricingRate {
        /** @var PricingRate|null $row */
        $row = $this->findOneBy([
            'categoryLabel' => $categoryLabel,
            'subcategory' => $subcategory,
            'zone' => $zone,
        ]);

        return $row;
    }

    /**
     * @return list<PricingRate>
     */
    public function findAllOrdered(): array
    {
        /** @var list<PricingRate> $rows */
        $rows = $this->createQueryBuilder('p')
            ->orderBy('p.categoryLabel', 'ASC')
            ->addOrderBy('p.subcategory', 'ASC')
            ->addOrderBy('p.zone', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
