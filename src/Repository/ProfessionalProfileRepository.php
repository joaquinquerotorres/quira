<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProfessionalProfile;
use App\Entity\Request;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProfessionalProfile>
 */
class ProfessionalProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProfessionalProfile::class);
    }

    /**
     * @return ProfessionalProfile[]
     */
    public function findMatchingPros(Request $request): array
{
    $qb = $this->createQueryBuilder('p');

    $category = $request->getCategory()->value;
    $location = $request->getLocationPoint();
    
    if (!$location) {
        return [];
    }

    $requestLat = $location->getLatitude();
    $requestLon = $location->getLongitude();

    $requestPointWKT = sprintf('POINT(%f %f)', $requestLon, $requestLat);

    return $qb
        ->where('p.isVerified = :verified')
        ->andWhere('p.skills LIKE :category')
        ->andWhere('ST_Distance_Sphere(p.locationPoint, ST_GeomFromText(:requestPoint)) <= (p.serviceRadiusKm * 1000)')
        ->distinct()
        ->setParameter('verified', true)
        ->setParameter('category', '%"' . $category . '"%')
        ->setParameter('requestPoint', $requestPointWKT)
        ->getQuery()
        ->getResult();
}
}