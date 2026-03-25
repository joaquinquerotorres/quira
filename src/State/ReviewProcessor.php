<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Review;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class ReviewProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security
    ) {
    }

    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = []
    ): mixed {
        if (!$data instanceof Review) {
            return $data;
        }

        $currentUser = $this->security->getUser();

        if (!$currentUser) {
            $this->logger->warning('Intento de crear una reseña sin estar logueado.');
            throw new AccessDeniedHttpException('Debes estar logueado para crear una reseña.');
        }

        if ($currentUser instanceof User) {
            $data->setAuthor($currentUser);
        }

        $this->entityManager->persist($data);
        $this->entityManager->flush();

        $this->recalculateTargetRating($data, $currentUser);
        $this->logger->info("Usuario {$currentUser->getUserIdentifier()} ha creado una reseña para el usuario {$data->getTarget()->getUserIdentifier()} con una puntuación de {$data->getScore()}.");

        return $data;
    }

    private function recalculateTargetRating(Review $review, ?User $author): void
    {
        if (!$author) {
            return;
        }

        $targetUser = $review->getTarget();
        $reviewRepository = $this->entityManager->getRepository(Review::class);

        $reviews = $reviewRepository->findBy(['target' => $targetUser]);
        $count = count($reviews);
        $average = 0.0;

        if ($count > 0) {
            $totalScore = array_reduce($reviews, fn (int $carry, Review $item) => $carry + $item->getScore(), 0);
            $average = round($totalScore / $count, 1);
        }

        $authorIsPro = $this->isUserProfessional($author);

        if ($authorIsPro) {
            $clientProfile = $targetUser->getClientProfile();
            if ($clientProfile) {
                $clientProfile->setRating($average);
                $clientProfile->setReviewCount($count);
                $this->entityManager->persist($clientProfile);
            }
        } else {
            $proProfile = $targetUser->getProfessionalProfile();
            if ($proProfile) {
                $proProfile->setRating($average);
                $proProfile->setReviewCount($count);
                $this->entityManager->persist($proProfile);
            }
        }

        $this->entityManager->flush();
    }

    private function isUserProfessional(User $user): bool
    {
        return in_array('ROLE_PROFESSIONAL', $user->getRoles(), true) || $user->getProfessionalProfile() !== null;
    }
}