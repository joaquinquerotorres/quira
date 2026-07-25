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

    public function findOneByRequestAndProfessional(Request $request, ProfessionalProfile $professional): ?CalendarEvent
    {
        return $this->findOneBy([
            'request' => $request,
            'professional' => $professional,
        ]);
    }
}
