<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Bid;
use App\Entity\Request;
use App\Entity\User;
use App\Enum\BidStatus;
use App\Enum\RequestStatus;
use App\Repository\BidRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class BidProfessionalProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface $persistProcessor
     * @param Security $security
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persistProcessor,
        private readonly LoggerInterface $logger,
        private readonly Security $security,
        private readonly BidRepository $bidRepository
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
        if ($data instanceof Bid && $operation instanceof Post) {
            /** @var User|null $user */
            $user = $this->security->getUser();
            if (!$user) {
                $this->logger->warning('Intento de hacer una oferta sin estar logueado.');
                throw new AccessDeniedHttpException('Debes estar logueado para hacer una oferta.');
            }

            /** @var Bid $bid */
            $bid = $data;
            $request = $bid->getRequest();
            
            if ($request === null) {
                $this->logger->error("La oferta con ID {$bid->getId()} no tiene una solicitud asociada.");
                throw new BadRequestHttpException('Esta oferta no está asociada a una solicitud.');
            }

            if ($request->getStatus() !== RequestStatus::PENDING) {
                $this->logger->warning("Intento de hacer una oferta para una solicitud que no está pendiente.");
                throw new BadRequestHttpException('La solicitud a la que pertenece esta oferta no está pendiente.');
            }

            $clientProfile = $request->getClient();
            if ($clientProfile === null) {
                $this->logger->error("La solicitud con ID {$request->getId()} no tiene un cliente asociado.");
                throw new BadRequestHttpException('La solicitud a la que pertenece esta oferta no tiene un cliente asociado.');
            }

            if ($request->getClient()->getUser() === $user) {
                $this->logger->warning("Usuario {$user->getUserIdentifier()} intentó hacer una oferta para su propia solicitud.");
                throw new AccessDeniedHttpException('No puedes hacer una oferta para tu propia solicitud.');
            }

            $professionalProfile = $user->getProfessionalProfile();
            if ($professionalProfile === null) {
                $this->logger->error("El usuario {$user->getUserIdentifier()} no tiene un perfil profesional asociado.");
                throw new AccessDeniedHttpException(
                    'Acceso denegado. Debes completar tu perfil profesional para hacer ofertas.'
                );
            }

            if (!$professionalProfile->isVerifiedPhone()) {
                $this->logger->warning('Intento de hacer una puja sin teléfono verificado.');
                throw new AccessDeniedHttpException(
                    'Debes verificar tu número de teléfono antes de hacer una puja. Añade y verifica tu teléfono en tu perfil profesional.'
                );
            }

            if (empty($professionalProfile->getPhoneNumber())) {
                $this->logger->warning('Intento de hacer una puja sin teléfono en el perfil profesional.');
                throw new AccessDeniedHttpException(
                    'Debes añadir tu número de teléfono en tu perfil profesional antes de hacer una puja.'
                );
            }

            if (in_array('ROLE_FREE', $user->getRoles(), true)) {
                if (!$this->bidRepository->canProfessionalBidThisMonth($user)) {
                    $this->logger->warning("Usuario FREE {$user->getUserIdentifier()} intentó pujar habiendo alcanzado el límite mensual.");
                    throw new BadRequestHttpException(
                        'Has alcanzado el límite de ' . BidRepository::BIDS_MONTHLY_LIMIT_FREE . ' pujas este mes. Actualiza tu plan para seguir pujando.'
                    );
                }
            }

            $bid->setStatus(BidStatus::PENDING);
            $bid->setProfessional($user);
            $this->logger->info("Usuario {$user->getUserIdentifier()} ha hecho una oferta de {$bid->getPriceQuote()}€ para la solicitud '{$request->getTitle()}'.");
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}