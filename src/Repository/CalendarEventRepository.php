<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CalendarEvent;
use App\Entity\ProfessionalProfile;
use App\Entity\Request;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CalendarEvent>
 */
class CalendarEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CalendarEvent::class);
    }

    /**
     * Como máximo uno por par; si hubiera duplicados legacy, devuelve el más reciente.
     */
    public function findOneByRequestAndProfessional(Request $request, ProfessionalProfile $professional): ?CalendarEvent
    {
        return $this->createQueryBuilder('ce')
            ->andWhere('ce.request = :request')
            ->andWhere('ce.professional = :professional')
            ->setParameter('request', $request)
            ->setParameter('professional', $professional)
            ->orderBy('ce.updatedAt', 'DESC')
            ->addOrderBy('ce.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
