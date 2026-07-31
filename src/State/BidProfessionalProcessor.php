<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Entity\Bid;
use App\Entity\Request;
use App\Entity\User;
use App\Enum\BidStatus;
use App\Enum\RequestStatus;
use App\Enum\RiskLevel;
use App\Repository\BidRepository;
use App\Service\ProfessionalSubscriptionService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

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
        private readonly BidRepository $bidRepository,
        private readonly ProfessionalSubscriptionService $subscriptionService,
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

            $existingBid = $this->bidRepository->findOneBy([
                'request' => $request,
                'professional' => $user,
            ]);
            if ($existingBid !== null) {
                $this->logger->warning("Usuario {$user->getUserIdentifier()} intentó crear una segunda oferta para la solicitud {$request->getId()}.");
                throw new BadRequestHttpException('Ya has enviado una oferta para esta solicitud.');
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

            if ($this->subscriptionService->isSubjectToFreeProfessionalLimits($user)) {
                if ($request->getRiskLevel() === RiskLevel::HIGH) {
                    $this->logger->warning("Usuario sin suscripción activa {$user->getUserIdentifier()} intentó pujar en solicitud HIGH.");
                    $this->throwBidValidation(
                        'riskLevel',
                        'Las solicitudes de riesgo alto requieren un plan de pago activo. Renueva tu suscripción para pujar en este tipo de trabajos.',
                        'BID_HIGH_REQUIRES_PAID_SUBSCRIPTION'
                    );
                }

                if (!$this->bidRepository->canProfessionalBidThisMonth($user)) {
                    $this->logger->warning("Usuario con límites FREE {$user->getUserIdentifier()} intentó pujar habiendo alcanzado el límite mensual.");
                    $this->throwBidValidation(
                        'monthlyBidLimit',
                        'Has alcanzado el límite de ' . BidRepository::BIDS_MONTHLY_LIMIT_FREE . ' pujas este mes. Actualiza tu plan para seguir pujando.',
                        'BID_MONTHLY_LIMIT_EXCEEDED'
                    );
                }
            }

            // El profesional elige FIXED|RANGE libremente; Request.pricingType es solo estimación IA.
            $bidPricingType = strtoupper($bid->getPricingType());
            if (!\in_array($bidPricingType, [Bid::PRICING_TYPE_FIXED, Bid::PRICING_TYPE_RANGE], true)) {
                $this->throwBidValidation(
                    'pricingType',
                    'El tipo de precio de la propuesta debe ser FIXED o RANGE.',
                    'BID_PRICING_TYPE_INVALID'
                );
            }

            if ($bidPricingType === Bid::PRICING_TYPE_RANGE) {
                $comment = $bid->getComment();
                if ($comment === null || trim($comment) === '') {
                    $this->throwBidValidation(
                        'comment',
                        'En una propuesta por rango debes explicar la horquilla de precio en el comentario.',
                        'BID_RANGE_COMMENT_REQUIRED'
                    );
                }
            }

            if ($bidPricingType === Bid::PRICING_TYPE_FIXED) {
                if (($bid->getPriceQuote() ?? 0) <= 0) {
                    $this->throwBidValidation(
                        'priceQuote',
                        'Debes indicar un precio fijo mayor que 0.',
                        'BID_FIXED_PRICE_REQUIRED'
                    );
                }
                $fixed = $bid->getPriceQuote();
                $bid->setPriceQuoteMin($fixed);
                $bid->setPriceQuoteMax($fixed);
            } else {
                $min = $bid->getPriceQuoteMin();
                $max = $bid->getPriceQuoteMax();
                if (($min ?? 0) <= 0 || ($max ?? 0) <= 0) {
                    $this->throwBidValidation(
                        'priceQuoteMin',
                        'Para propuesta por rango debes indicar priceQuoteMin y priceQuoteMax mayores que 0.',
                        'BID_RANGE_PRICES_REQUIRED'
                    );
                }
                if ($max < $min) {
                    $this->throwBidValidation(
                        'priceQuoteMax',
                        'priceQuoteMax debe ser mayor o igual que priceQuoteMin.',
                        'BID_RANGE_INVALID'
                    );
                }
                // Compatibilidad legacy: mantener priceQuote con el mínimo del rango.
                $bid->setPriceQuote($min);
            }

            $bid->setStatus(BidStatus::PENDING);
            $bid->setProfessional($user);
            $this->logger->info("Usuario {$user->getUserIdentifier()} ha hecho una oferta ({$bidPricingType}) para la solicitud '{$request->getTitle()}'.");
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }

    private function throwBidValidation(string $propertyPath, string $message, string $code): never
    {
        $violations = new ConstraintViolationList([
            new ConstraintViolation($message, null, [], null, $propertyPath, null, null, $code),
        ]);
        throw new ValidationException($violations);
    }
}