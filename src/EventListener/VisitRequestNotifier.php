<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\VisitRequest;
use App\Enum\RiskLevel;
use App\Service\NotificationService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Psr\Log\LoggerInterface;

#[AsEntityListener(event: Events::postPersist, method: 'postPersist', entity: VisitRequest::class)]
#[AsEntityListener(event: Events::postUpdate, method: 'postUpdate', entity: VisitRequest::class)]
final class VisitRequestNotifier
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly NotificationService $notificationService,
    ) {
    }

    public function postPersist(VisitRequest $visit, LifecycleEventArgs $event): void
    {
        $request = $visit->getRequest();
        $proProfile = $visit->getProfessional();

        if ($request === null || $proProfile === null) {
            $this->logger->warning('❌ No se pudo notificar la solicitud de visita: datos incompletos.');
            return;
        }

        $clientProfile = $request->getClient();
        if ($clientProfile === null) {
            $this->logger->error('❌ Error: La solicitud no tiene perfil de cliente asociado al crear una visita.');
            return;
        }

        if ($clientProfile->getNotifyRequestActivity() === false) {
            $this->logger->info(sprintf('🔕 El cliente %s ha desactivado las notificaciones de actividad en solicitudes. Saltando notificación de visita.', $clientProfile->getFullName() ?? ''));
            return;
        }

        $clientUser = $clientProfile->getUser();
        if ($clientUser === null) {
            $this->logger->error('❌ Error: El ClientProfile no tiene User asociado al crear una visita.');
            return;
        }

        $title = $request->getTitle() ?? 'tu solicitud';
        $proName = $proProfile->getFullName() ?? 'un profesional';

        $body = sprintf(
            '%s ha solicitado una visita de valoración para "%s". Puedes aceptarla o rechazarla desde la app.',
            $proName,
            $title,
        );

        try {
            $this->logger->info(sprintf(
                '🔔 Notificando al cliente %s sobre nueva solicitud de visita en la request #%d.',
                $clientUser->getUserIdentifier(),
                $request->getId() ?? 0
            ));

            $this->notificationService->send(
                $clientUser,
                'Nueva solicitud de visita',
                $body,
                'VISIT_REQUEST_CREATED',
                $request->getId()
            );
        } catch (\Throwable $e) {
            $this->logger->error('❌ Error enviando notificación de solicitud de visita: ' . $e->getMessage());
        }
    }

    public function postUpdate(VisitRequest $visit, LifecycleEventArgs $event): void
    {
        $request = $visit->getRequest();
        $proProfile = $visit->getProfessional();

        if ($request === null || $proProfile === null) {
            $this->logger->warning('❌ No se pudo notificar el cambio de estado de visita: datos incompletos.');
            return;
        }

        $proUser = $proProfile->getUser();
        if ($proUser === null) {
            $this->logger->error('❌ Error: El ProfessionalProfile no tiene User asociado al actualizar una visita.');
            return;
        }

        if ($proProfile->getNotifyRequestActivity() === false) {
            $this->logger->info(sprintf(
                '🔕 El profesional %s ha desactivado las notificaciones de actividad en solicitudes. Saltando notificación de visita.',
                $proProfile->getFullName() ?? ''
            ));
            return;
        }

        $status = $visit->getStatus();
        if ($status === VisitRequest::STATUS_ACCEPTED) {
            $title = 'Visita aceptada';
            $body = sprintf(
                'El cliente ha aceptado tu solicitud de visita para "%s".',
                $request->getTitle() ?? 'una de tus solicitudes',
            );
            $type = 'VISIT_REQUEST_ACCEPTED';
        } elseif ($status === VisitRequest::STATUS_REJECTED) {
            $title = 'Visita rechazada';
            $body = sprintf(
                'El cliente ha rechazado tu solicitud de visita para "%s".',
                $request->getTitle() ?? 'una de tus solicitudes',
            );
            $type = 'VISIT_REQUEST_REJECTED';
        } else {
            // Otros cambios de estado no generan notificación específica.
            return;
        }

        try {
            $this->logger->info(sprintf(
                '🔔 Notificando al profesional %s sobre cambio de estado de visita (%s) en la request #%d.',
                $proUser->getUserIdentifier(),
                $status,
                $request->getId() ?? 0
            ));

            $this->notificationService->send(
                $proUser,
                $title,
                $body,
                $type,
                $request->getId()
            );
        } catch (\Throwable $e) {
            $this->logger->error('❌ Error enviando notificación de cambio de estado de visita: ' . $e->getMessage());
        }
    }
}

