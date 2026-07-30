<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProfessionalProfile;
use App\Entity\Review;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Review>
 *
 * @method Review|null find($id, $lockMode = null, $lockVersion = null)
 * @method Review|null findOneBy(array $criteria, array $orderBy = null)
 * @method Review[]    findAll()
 * @method Review[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ReviewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Review::class);
    }

    /**
     * Reseñas recibidas como profesional asignado del trabajo (últimas N).
     *
     * @return list<array{id: ?int, score: ?int, comment: ?string, authorName: ?string, createdAt: ?string}>
     */
    public function findRecentSerializedForProfessionalProfile(ProfessionalProfile $profile, int $limit = 30): array
    {
        $user = $profile->getUser();
        if ($user === null) {
            return [];
        }

        /** @var list<Review> $reviews */
        $reviews = $this->createQueryBuilder('r')
            ->innerJoin('r.request', 'req')
            ->addSelect('req')
            ->leftJoin('r.author', 'author')
            ->addSelect('author')
            ->leftJoin('author.clientProfile', 'author_client')
            ->addSelect('author_client')
            ->leftJoin('author.professionalProfile', 'author_pro')
            ->addSelect('author_pro')
            ->andWhere('r.target = :target')
            ->andWhere('req.assignedProfessional = :profile')
            ->setParameter('target', $user)
            ->setParameter('profile', $profile)
            ->orderBy('r.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $result = [];
        foreach ($reviews as $review) {
            $createdAt = $review->getCreatedAt();
            $result[] = [
                'id' => $review->getId(),
                'score' => $review->getScore(),
                'comment' => $review->getComment(),
                'authorName' => $review->getAuthorDisplayName(),
                'createdAt' => $createdAt ? $createdAt->format(\DateTimeInterface::ATOM) : null,
            ];
        }

        return $result;
    }
}
