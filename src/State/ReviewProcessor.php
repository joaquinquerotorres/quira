<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Review;
use App\Entity\User;
use App\Enum\RequestStatus;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

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

        if (!$currentUser instanceof User) {
            $this->logger->warning('Intento de crear una reseña sin estar logueado.');
            throw new AccessDeniedHttpException('Debes estar logueado para crear una reseña.');
        }

        $request = $data->getRequest();
        $target = $data->getTarget();
        if ($request === null || $target === null) {
            throw new BadRequestHttpException('La reseña debe incluir solicitud y destinatario.');
        }

        if (!in_array($request->getStatus(), [RequestStatus::ACCEPTED, RequestStatus::COMPLETED], true)) {
            throw new BadRequestHttpException('Solo puedes valorar trabajos aceptados o completados.');
        }

        $clientUser = $request->getClient()?->getUser();
        $assignedProUser = $request->getAssignedProfessional()?->getUser();
        if ($clientUser === null || $assignedProUser === null) {
            throw new BadRequestHttpException('La solicitud no tiene las partes necesarias para valorar.');
        }

        $isClientAuthor = $currentUser === $clientUser;
        $isProAuthor = $currentUser === $assignedProUser;
        if (!$isClientAuthor && !$isProAuthor) {
            $this->logger->warning("Usuario {$currentUser->getUserIdentifier()} intentó crear una reseña en una solicitud ajena.");
            throw new AccessDeniedHttpException('Solo las partes de la solicitud pueden crear una reseña.');
        }

        $expectedTarget = $isClientAuthor ? $assignedProUser : $clientUser;
        if ($target !== $expectedTarget) {
            throw new BadRequestHttpException('El destinatario de la reseña no es válido para esta solicitud.');
        }

        $data->setAuthor($currentUser);

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
        $authorIsPro = $this->isUserProfessional($author);
        $reviewRepository = $this->entityManager->getRepository(Review::class);

        // Facet: reviews written by professionals update client rating; by clients update pro rating.
        $reviews = array_values(array_filter(
            $reviewRepository->findBy(['target' => $targetUser]),
            fn (Review $item): bool => $this->isUserProfessional($item->getAuthor()) === $authorIsPro
        ));
        $count = count($reviews);
        $average = 0.0;

        if ($count > 0) {
            $totalScore = array_reduce($reviews, fn (int $carry, Review $item) => $carry + $item->getScore(), 0);
            $average = round($totalScore / $count, 1);
        }

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
        return $user->isProfessionalActor();
    }
}
