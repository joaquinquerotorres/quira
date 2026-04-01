<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Bid;
use App\Entity\User;
use App\Enum\BidStatus;
use App\Enum\RequestStatus;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class BidWithdrawProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.remove_processor')]
        private readonly ProcessorInterface $removeProcessor,
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
        if (!$data instanceof Bid || !$operation instanceof Delete) {
            return $data;
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

        if ($bid->getStatus() !== BidStatus::PENDING) {
            throw new BadRequestHttpException('Solo puedes retirar propuestas pendientes.');
        }

        $request = $bid->getRequest();
        if ($request === null) {
            throw new BadRequestHttpException('Esta oferta no está asociada a una solicitud.');
        }

        if ($request->getStatus() !== RequestStatus::PENDING) {
            throw new BadRequestHttpException('Solo puedes retirar propuestas en solicitudes pendientes (sin profesional asignado).');
        }

        $this->logger->info("Usuario {$user->getUserIdentifier()} ha retirado su propuesta (bid {$bid->getId()}) de la solicitud '{$request->getTitle()}'. Se eliminará de BD.");

        return $this->removeProcessor->process($data, $operation, $uriVariables, $context);
    }
}
