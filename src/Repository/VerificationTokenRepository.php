<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\VerificationToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VerificationToken>
 */
class VerificationTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VerificationToken::class);
    }

    public function findValidByToken(string $token, string $type): ?VerificationToken
    {
        $entity = $this->findOneBy(
            ['token' => $token, 'type' => $type],
            ['id' => 'DESC']
        );

        return $entity && !$entity->isExpired() ? $entity : null;
    }

    public function deleteForUser(User $user, string $type): void
    {
        $this->getEntityManager()->createQuery(
            'DELETE FROM App\Entity\VerificationToken t WHERE t.user = :user AND t.type = :type'
        )->setParameter('user', $user)->setParameter('type', $type)->execute();
    }
}
