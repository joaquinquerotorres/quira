<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Bid;
use App\Enum\RequestStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Bid>
 *
 * @method Bid|null find($id, $lockMode = null, $lockVersion = null)
 * @method Bid|null findOneBy(array $criteria, array $orderBy = null)
 * @method Bid[]    findAll()
 * @method Bid[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class BidRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Bid::class);
    }

    public const BIDS_MONTHLY_LIMIT_FREE = 3;

    public function countByProfessionalThisMonth(\App\Entity\User $professional): int
    {
        $start = new \DateTimeImmutable('first day of this month 00:00:00');
        $end = new \DateTimeImmutable('last day of this month 23:59:59');

        return (int) $this->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->innerJoin('b.request', 'r')
            ->where('b.professional = :professional')
            ->andWhere('b.createdAt >= :start')
            ->andWhere('b.createdAt <= :end')
            ->andWhere('r.status != :request_cancelled')
            ->setParameter('professional', $professional)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->setParameter('request_cancelled', RequestStatus::CANCELLED)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function canProfessionalBidThisMonth(\App\Entity\User $professional): bool
    {
        return $this->countByProfessionalThisMonth($professional) < self::BIDS_MONTHLY_LIMIT_FREE;
    }
}