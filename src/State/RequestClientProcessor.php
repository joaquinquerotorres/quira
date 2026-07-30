<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Request;
use App\Entity\User;
use App\Enum\RequestStatus;
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
        private readonly MediaService $mediaService
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

            [$isSafe, $moderationReason] = $this->resolveSafetyFromDiagnosis($data->getAiDiagnosis());

            if ($isSafe === false) {
                $data->setStatus(RequestStatus::PENDING_APPROVAL); 
                $data->setIsFlagged(true);
                $data->setModerationReason($moderationReason);
                $this->logger->info("La solicitud '{$data->getTitle()}' del usuario {$user->getUserIdentifier()} ha sido marcada para revisión por contenido inseguro. Razón: {$moderationReason}.");
            } else {
                $data->setStatus(RequestStatus::PENDING);
                $data->setIsFlagged(false);
                $data->setModerationReason(null);
                $this->logger->info("La solicitud '{$data->getTitle()}' del usuario {$user->getUserIdentifier()} ha pasado la revisión de seguridad y está pendiente de ofertas.");
            }
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }

    /**
     * Solo `safe` / `is_safe` decide PENDING_APPROVAL.
     * `in_scope=false` (fuera de catálogo Quira) NO marca la solicitud: la app debe
     * mostrar UX de “no cubrimos esto” sin pasar por moderación humana.
     *
     * @param array<string, mixed>|null $aiDiagnosis
     * @return array{0: bool, 1: ?string}
     */
    private function resolveSafetyFromDiagnosis(?array $aiDiagnosis): array
    {
        if ($aiDiagnosis === null) {
            return [true, null];
        }

        $safeValue = $aiDiagnosis['safe'] ?? $aiDiagnosis['is_safe'] ?? true;
        $isSafe = filter_var($safeValue, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($isSafe === null) {
            $isSafe = true;
        }

        $reasonValue = $aiDiagnosis['safety_reason'] ?? $aiDiagnosis['reason'] ?? null;
        $reason = is_string($reasonValue) && $reasonValue !== '' ? $reasonValue : 'Contenido marcado por diagnóstico IA.';

        return [$isSafe, $isSafe ? null : $reason];
    }
}