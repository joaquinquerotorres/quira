<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Bid;
use App\Enum\BidStatus;
use App\Service\NotificationService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Psr\Log\LoggerInterface;

#[AsEntityListener(event: Events::postUpdate, method: 'patchPersist', entity: Bid::class)]
final class BidAcceptanceNotifier
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly NotificationService $notificationService
    ) {
    }

    public function patchPersist(Bid $bid, LifecycleEventArgs $event): void
    {
        if ($bid->getStatus() !== BidStatus::ACCEPTED) {
            $this->logger->info("El estado de la oferta no es ACCEPTED. Saltando notificación.");
            return;
        }

        $request = $bid->getRequest();
        if (null === $request) {
            $this->logger->error("❌ Error: La oferta con ID {$bid->getId()} no tiene una solicitud asociada.");
            return;
        }

        $request = $bid->getRequest();
        $clientProfile = $request?->getClient();

        if (null === $clientProfile) {
            $this->logger->error("❌ Error: El cliente no tiene un perfil de cliente asociado.");
            return;
        }

        $proProfile = $bid->getProfessional()?->getProfessionalProfile();
        if (null === $proProfile) {
            $this->logger->error("❌ Error: El profesional no tiene un perfil profesional asociado.");
            return;
        }

        $proUser = $proProfile->getUser();
        if (null === $proUser) {
            $this->logger->error("❌ Error: El profesional {$proProfile->getFullName()} no tiene un usuario asociado.");
            return;
        }

        if ($proProfile->getNotifyBidActivity() === false) {
            $this->logger->info("🔕 El profesional {$proProfile->getFullName()} ha desactivado las notificaciones de aceptación de ofertas. Saltando...");
            return;
        }

        try {
            $this->notificationService->send(
                $proUser,
                '¡Oferta aceptada!',
                sprintf(
                    '%s ha aceptado tu oferta de %s€ para "%s".',
                    $clientProfile->getFullName() ?? 'Un cliente',
                    $bid->getPriceQuote(),
                    $request->getTitle()
                ),
                'BID_ACCEPTED',
                $request->getId()
            );
        } catch (\Throwable $e) {
            $this->logger->error("Error notificando aceptación de oferta: " . $e->getMessage());
        }
    }
}