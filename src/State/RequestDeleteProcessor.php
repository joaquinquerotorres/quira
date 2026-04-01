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
        foreach ($reviewRepo->findBy(['request' => $data]) as $review) {
            $this->entityManager->remove($review);
        }

        $this->supabaseUploadTicketService->deleteManyPublicFiles(array_values(array_unique($mediaUrls)));

        $this->logger->info(sprintf(
            'Solicitud %d cancelada por cliente %s. Se eliminaron datos dependientes y media asociada.',
            $data->getId() ?? 0,
            $user->getUserIdentifier()
        ));

        return $this->removeProcessor->process($data, $operation, $uriVariables, $context);
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

