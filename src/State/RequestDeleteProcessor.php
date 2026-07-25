<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Request;
use App\Entity\Review;
use App\Entity\User;
use App\Enum\RequestStatus;
use App\Service\SupabaseUploadTicketService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class RequestDeleteProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.remove_processor')]
        private readonly ProcessorInterface $removeProcessor,
        private readonly Security $security,
        private readonly EntityManagerInterface $entityManager,
        private readonly SupabaseUploadTicketService $supabaseUploadTicketService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = []
    ): mixed {
        if (!$data instanceof Request || !$operation instanceof Delete) {
            return $data;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException('Debes estar logueado para cancelar una solicitud.');
        }

        $clientUser = $data->getClient()?->getUser();
        if ($clientUser !== $user) {
            throw new AccessDeniedHttpException('Solo puedes cancelar tus propias solicitudes.');
        }

        if (!in_array($data->getStatus(), [RequestStatus::PENDING, RequestStatus::PENDING_APPROVAL], true)) {
            throw new BadRequestHttpException('Solo puedes cancelar solicitudes pendientes.');
        }

        $mediaUrls = [];
        $mediaUrls = array_merge(
            $mediaUrls,
            $this->stringValues([$data->getPhotoUrl(), $data->getAudioUrl(), $data->getVideoUrl()]),
            $this->stringValues($data->getExtraPhotoUrls()),
            $this->stringValues($data->getExtraAudioUrls()),
            $this->stringValues($data->getExtraVideoUrls()),
        );

        foreach ($data->getQuestions() as $question) {
            $mediaUrls = array_merge($mediaUrls, $this->stringValues($question->getAnswerMediaUrls()));
            $this->entityManager->remove($question);
        }

        foreach ($data->getBids() as $bid) {
            $this->entityManager->remove($bid);
        }

        foreach ($data->getVisitRequests() as $visitRequest) {
            $this->entityManager->remove($visitRequest);
        }

        $reviewRepo = $this->entityManager->getRepository(Review::class);
        $affectedTargets = [];
        foreach ($reviewRepo->findBy(['request' => $data]) as $review) {
            $target = $review->getTarget();
            $author = $review->getAuthor();
            if ($target !== null && $author !== null) {
                $affectedTargets[] = [$target, $this->isUserProfessional($author)];
            }
            $this->entityManager->remove($review);
        }

        $this->supabaseUploadTicketService->deleteManyPublicFiles(array_values(array_unique($mediaUrls)));

        $this->logger->info(sprintf(
            'Solicitud %d cancelada por cliente %s. Se eliminaron datos dependientes y media asociada.',
            $data->getId() ?? 0,
            $user->getUserIdentifier()
        ));

        $result = $this->removeProcessor->process($data, $operation, $uriVariables, $context);

        foreach ($affectedTargets as [$targetUser, $authorWasPro]) {
            $this->recalculateTargetRating($targetUser, $authorWasPro);
        }

        return $result;
    }

    private function recalculateTargetRating(User $targetUser, bool $authorWasPro): void
    {
        $reviewRepository = $this->entityManager->getRepository(Review::class);
        $reviews = array_values(array_filter(
            $reviewRepository->findBy(['target' => $targetUser]),
            fn (Review $item): bool => $item->getAuthor() !== null
                && $this->isUserProfessional($item->getAuthor()) === $authorWasPro
        ));
        $count = count($reviews);
        $average = 0.0;
        if ($count > 0) {
            $totalScore = array_reduce($reviews, fn (int $carry, Review $item) => $carry + $item->getScore(), 0);
            $average = round($totalScore / $count, 1);
        }

        if ($authorWasPro) {
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

    /**
     * @param array<mixed>|null $values
     * @return array<int,string>
     */
    private function stringValues(?array $values): array
    {
        if ($values === null) {
            return [];
        }

        $result = [];
        foreach ($values as $value) {
            if (is_string($value) && $value !== '') {
                $result[] = $value;
            }
        }

        return $result;
    }
}
