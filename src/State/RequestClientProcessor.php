<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Request;
use App\Entity\User;
use App\Enum\RequestStatus;
use App\Service\GeminiService;
use App\Service\MediaService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class RequestClientProcessor implements ProcessorInterface
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
        private readonly MediaService $mediaService,
        private readonly GeminiService $geminiService
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
        if ($data instanceof Request && $operation instanceof Post) {
            /** @var User|null $user */
            $user = $this->security->getUser();

            if (!$user) {
                $this->logger->warning('Intento de crear una solicitud de servicio sin estar logueado.');
                throw new AccessDeniedHttpException('Debes estar logueado para crear una solicitud de servicio.');
            }

            $clientProfile = $user->getClientProfile();

            if ($clientProfile === null) {
                $this->logger->warning('Intento de crear una solicitud de servicio sin tener un perfil de cliente asociado.');
                throw new AccessDeniedHttpException(
                    'Tu cuenta no tiene un cliente asociado. Por favor completa tu registro.'
                );
            }

            if (!$clientProfile->isVerifiedPhone()) {
                $this->logger->warning('Intento de crear una solicitud sin teléfono verificado.');
                throw new AccessDeniedHttpException(
                    'Debes verificar tu número de teléfono antes de crear una solicitud. Añade y verifica tu teléfono en tu perfil de cliente.'
                );
            }

            if (empty($clientProfile->getPhoneNumber())) {
                $this->logger->warning('Intento de crear una solicitud sin teléfono en el perfil de cliente.');
                throw new AccessDeniedHttpException(
                    'Debes añadir tu número de teléfono en tu perfil de cliente antes de crear una solicitud.'
                );
            }

            $data->setClient($clientProfile);

            if ($data->photoBase64) {
                $publicUrl = $this->mediaService->saveRequestMediaFile($data->photoBase64, 'requests', 'image');
                $data->setPhotoUrl($publicUrl);
            }

            if ($data->audioBase64) {
                $publicUrl = $this->mediaService->saveRequestMediaFile($data->audioBase64, 'requests', 'audio');
                $data->setAudioUrl($publicUrl);
            }

            if ($data->videoBase64) {
                $publicUrl = $this->mediaService->saveRequestMediaFile($data->videoBase64, 'requests', 'video');
                $data->setVideoUrl($publicUrl);
            }

            $securityCheck = $this->geminiService->checkSafety(
                title: $data->getTitle() ?? '',
                description: $data->getDescription() ?? '',
                image: $data->photoBase64,
                audio: $data->audioBase64,
                video: $data->videoBase64
            );

            if ($securityCheck['is_safe'] === false) {
                $data->setStatus(RequestStatus::PENDING_APPROVAL); 
                $data->setIsFlagged(true);
                $data->setModerationReason($securityCheck['reason']);
                $this->logger->info("La solicitud '{$data->getTitle()}' del usuario {$user->getUserIdentifier()} ha sido marcada para revisión por contenido inseguro. Razón: {$securityCheck['reason']}.");
            } else {
                $data->setStatus(RequestStatus::PENDING);
                $data->setIsFlagged(false);
                $data->setModerationReason(null);
                $this->logger->info("La solicitud '{$data->getTitle()}' del usuario {$user->getUserIdentifier()} ha pasado la revisión de seguridad y está pendiente de ofertas.");
            }
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}