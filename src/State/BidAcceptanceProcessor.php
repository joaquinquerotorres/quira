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
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class BidAcceptanceProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface $persistProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persistProcessor,
        private readonly LoggerInterface $logger,
        private readonly Security $security,
    ) {
    }

    /**
     * @param mixed $data
     * @param Operation $operation
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     * @return mixed
     */
    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = []
    ): mixed {
        if ($data instanceof Bid && $operation instanceof Patch) {
            /** @var User|null $user */
            $user = $this->security->getUser();
            if (!$user) {
                $this->logger->warning('Intento de aceptar una oferta sin estar logueado.');
                throw new AccessDeniedHttpException('Debes estar logueado para aceptar una oferta.');
            }

            /** @var Bid $bid */
            $bid = $data;
            if ($bid->getStatus() !== BidStatus::PENDING) {
                $this->logger->warning('Intento de aceptar una oferta que no está pendiente.');
                throw new BadRequestHttpException('Sólo se pueden aceptar ofertas pendientes.');
            }

            $request = $bid->getRequest();
            
            if ($request === null) {
                $this->logger->error("La oferta con ID {$bid->getId()} no tiene una solicitud asociada.");
                throw new BadRequestHttpException('Esta oferta no está asociada a una solicitud.');
            }

            if ($request->getStatus() !== RequestStatus::PENDING) {
                $this->logger->warning('Intento de aceptar una oferta para una solicitud que no está pendiente.');
                throw new BadRequestHttpException('Solo se pueden aceptar ofertas de solicitudes pendientes.');
            }

            /** @var User|null $user */
            $user = $this->security->getUser();
            $clientProfile = $user->getClientProfile();

            if ($request->getClient() !== $clientProfile) {
                $this->logger->warning("Usuario {$user->getUserIdentifier()} intentó aceptar una oferta para una solicitud que no le pertenece.");
                throw new AccessDeniedHttpException('Sólo puedes aceptar ofertas para tus propias solicitudes.');
            }

            $preciseAddress = $request->getPreciseAddress();
            if ($preciseAddress === null || trim($preciseAddress) === '') {
                $this->logger->warning('Intento de aceptar una oferta sin dirección exacta en la solicitud.');
                throw new BadRequestHttpException('Debes indicar la dirección exacta antes de aceptar la oferta.');
            }

            $proUser = $bid->getProfessional();
            $proProfile = $proUser->getProfessionalProfile();
            if (!$proProfile) {
                $this->logger->error("El profesional {$proUser->getUserIdentifier()} no tiene un perfil profesional asociado.");
                throw new BadRequestHttpException('El profesional no tiene un perfil profesional activo.');
            }

            $bid->setStatus(BidStatus::ACCEPTED);
            $request->setStatus(RequestStatus::ACCEPTED);
            $request->setAssignedProfessional($proProfile);

            foreach ($request->getBids() as $siblingBid) {
                if ($siblingBid !== $bid && $siblingBid->getStatus() === BidStatus::PENDING) {
                    $siblingBid->setStatus(BidStatus::REJECTED);
                }
            }

            $this->logger->info("Usuario {$user->getUserIdentifier()} ha aceptado la oferta con ID {$bid->getId()} para la solicitud '{$request->getTitle()}'.");
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}