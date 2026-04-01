<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Put;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\ClientProfile;
use App\Entity\ProfessionalProfile;
use App\Entity\User;
use App\Service\PhoneComparisonService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class ClientProfileOwnerProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persistProcessor,
        private readonly LoggerInterface $logger,
        private readonly Security $security,
        private readonly PhoneComparisonService $phoneComparisonService,
    ) {
    }

    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = []
    ): mixed {
        if (!$data instanceof ClientProfile) {
            return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
        }

        /** @var User|null $user */
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            $this->logger->warning('Intento de editar perfil de cliente sin sesión.');
            throw new AccessDeniedHttpException('Debes estar logueado para editar tu perfil de cliente.');
        }

        if (!($operation instanceof Patch || $operation instanceof Put)) {
            return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
        }

        /** @var ClientProfile|null $previous */
        $previous = $context['previous_data'] ?? null;
        $phoneChanged = $this->phoneComparisonService->normalizeForComparison($previous?->getPhoneNumber())
            !== $this->phoneComparisonService->normalizeForComparison($data->getPhoneNumber());

        if ($phoneChanged) {
            $data->setVerifiedPhone($this->canAutoVerifyFromProfessional($data, $user));
        } elseif ($previous instanceof ClientProfile) {
            // No confiar en verifiedPhone enviado por el front si el teléfono no cambió.
            $data->setVerifiedPhone($previous->isVerifiedPhone());
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }

    private function canAutoVerifyFromProfessional(ClientProfile $clientProfile, User $user): bool
    {
        $professionalProfile = $user->getProfessionalProfile();
        if (!$professionalProfile instanceof ProfessionalProfile || !$professionalProfile->isVerifiedPhone()) {
            return false;
        }

        return $this->phoneComparisonService->areEquivalent(
            $clientProfile->getPhoneNumber(),
            $professionalProfile->getPhoneNumber()
        );
    }
}

