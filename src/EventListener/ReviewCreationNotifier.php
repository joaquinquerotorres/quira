<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Review;
use App\Enum\NotificationAudience;
use App\Service\NotificationService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Psr\Log\LoggerInterface;

#[AsEntityListener(event: Events::postPersist, method: 'postPersist', entity: Review::class)]
final class ReviewCreationNotifier
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly NotificationService $notificationService
    ) {
    }

    public function postPersist(Review $review, LifecycleEventArgs $event): void
    {
        $author = $review->getAuthor();
        $target = $review->getTarget();
        $request = $review->getRequest();

        if (null === $author || null === $target || null === $request) {
            $this->logger->warning("❌ No se pudo notificar la reseña: datos incompletos.");
            return;
        }

        if ($author->getClientProfile() && $target->getProfessionalProfile() && $target->getProfessionalProfile()->getNotifyReviews() !== false) {
            $this->logger->info("🔔 Notificando al profesional {$target->getUserIdentifier()} sobre la nueva reseña recibida.");
            $this->notificationService->send(
                $target,
                '¡Has recibido una nueva reseña! ⭐',
                sprintf(
                    'Un cliente te ha valorado con %d estrellas por el trabajo "%s". ¡Buen trabajo!',
                    $review->getScore() ?? 0,
                    $request?->getTitle() ?? 'Servicio realizado'
                ),
                'REVIEW_RECEIVED',
                NotificationAudience::Professional,
                $request?->getId()
            );
        }

        if ($author->getProfessionalProfile() && $target->getClientProfile() && $target->getClientProfile()->getNotifyReviews() !== false) {
            $this->logger->info("🔔 Notificando al cliente {$target->getUserIdentifier()} sobre la nueva valoración recibida.");
            $this->notificationService->send(
                $target,
                'Nueva valoración recibida',
                sprintf(
                    '%s te ha dejado una valoración como cliente. ¡Sigue así para atraer a los mejores profesionales!',
                    $author->getProfessionalProfile()->getFullName()
                ),
                'REVIEW_RECEIVED',
                NotificationAudience::Client,
                $request?->getId()
            );
        }
    }
}