<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Bid;
use App\Entity\User;
use App\Enum\BidStatus;
use App\Enum\RequestStatus;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class BidWithdrawProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persistProcessor,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly Security $security,
    ) {
    }

    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = []
    ): mixed {
        if (!$data instanceof Bid || !$operation instanceof Patch) {
            return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException('Debes estar logueado para retirar una propuesta.');
        }

        $bid = $data;

        if ($bid->getProfessional() !== $user) {
            $this->logger->warning("Usuario {$user->getUserIdentifier()} intentó retirar una puja que no es suya.");
            throw new AccessDeniedHttpException('Solo puedes retirar tus propias propuestas.');
        }

        // Check original DB state: deserializer merges {status:REJECTED} before processor runs
        $unitOfWork = $this->entityManager->getUnitOfWork();
        $originalData = $unitOfWork->getOriginalEntityData($bid);
        $originalStatus = $originalData['status'] ?? null;
        $wasPending = $originalStatus === BidStatus::PENDING || $originalStatus === BidStatus::PENDING->value;
        if (!$wasPending) {
            throw new BadRequestHttpException('Solo puedes retirar propuestas pendientes.');
        }

        $request = $bid->getRequest();
        if ($request === null) {
            throw new BadRequestHttpException('Esta oferta no está asociada a una solicitud.');
        }

        if ($request->getStatus() !== RequestStatus::PENDING) {
            throw new BadRequestHttpException('Solo puedes retirar propuestas en solicitudes pendientes (sin profesional asignado).');
        }

        $bid->setStatus(BidStatus::REJECTED);

        $this->logger->info("Usuario {$user->getUserIdentifier()} ha retirado su propuesta (bid {$bid->getId()}) de la solicitud '{$request->getTitle()}'.");

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
