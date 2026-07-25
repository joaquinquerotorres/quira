<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Bid;
use App\Enum\BidStatus;
use App\Enum\NotificationAudience;
use App\Service\NotificationService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Psr\Log\LoggerInterface;

#[AsEntityListener(event: Events::postPersist, method: 'postPersist', entity: Bid::class)]
final class BidCreationNotifier
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly NotificationService $notificationService
    ) {
    }

    public function postPersist(Bid $bid, LifecycleEventArgs $event): void
    {
        if ($bid->getStatus() !== BidStatus::PENDING) {
            $this->logger->info("El estado de la oferta no es PENDING. Saltando notificación.");
            return;
        }

        $request = $bid->getRequest();
        if (null === $request) {
            $this->logger->error("❌ Error: La oferta con ID {$bid->getId()} no tiene una solicitud asociada.");
            return;
        }

        $clientProfile = $request?->getClient();
        if (null === $clientProfile) {
            $this->logger->error("❌ Error: El cliente no tiene un perfil de cliente asociado.");
            return;
        }

        $clientUser = $clientProfile->getUser();
        if (null === $clientUser) {
            $this->logger->error("❌ Error: El cliente {$clientProfile->getFullName()} no tiene un usuario asociado.");
            return;
        }

        $proUser = $bid->getProfessional();
        if (null === $proUser) {
            $this->logger->error("❌ Error: El profesional no tiene un usuario asociado.");
            return;
        }

        $proProfile = $proUser->getProfessionalProfile();
        if (null === $proProfile) {
            $this->logger->error("❌ Error: El profesional {$proUser->getUserIdentifier()} no tiene un perfil profesional asociado.");
            return;
        }

        if ($clientProfile->getNotifyBidActivity() === false) {
            $this->logger->info("🔕 El cliente {$clientUser->getUserIdentifier()} ha desactivado las notificaciones de nuevas ofertas. Saltando...");
            return;
        }

        $amountEuros = number_format(($bid->getPriceQuote() ?? 0) / 100, 2, '.', '');

        try {
            $this->notificationService->send(
                $clientUser,
                '¡Nueva oferta recibida!',
                sprintf(
                    '%s te ha enviado una oferta de %s€ para "%s".',
                    $proProfile->getFullName() ?? 'Un profesional',
                    $amountEuros,
                    $request->getTitle()
                ),
                'BID_RECEIVED',
                NotificationAudience::Client,
                $request->getId(),
                [
                    'professionalName' => $proProfile->getFullName() ?? 'Un profesional',
                    'amount' => $amountEuros,
                    'requestTitle' => $request->getTitle() ?? '',
                ]
            );
        } catch (\Exception $e) {
            $this->logger->error("❌ Error al enviar la notificación de nueva oferta: " . $e->getMessage());
        }
    }
}