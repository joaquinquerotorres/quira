<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PredictTask;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PredictTask>
 */
class PredictTaskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PredictTask::class);
    }

    public function findOneByPublicId(string $publicId): ?PredictTask
    {
        return $this->findOneBy(['publicId' => $publicId]);
    }
}
