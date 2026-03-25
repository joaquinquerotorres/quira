<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\RequestQuestion;
use App\Service\NotificationService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Psr\Log\LoggerInterface;

#[AsEntityListener(event: Events::postPersist, method: 'postPersist', entity: RequestQuestion::class)]
#[AsEntityListener(event: Events::postUpdate, method: 'postUpdate', entity: RequestQuestion::class)]
final class RequestQuestionNotifier
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly NotificationService $notificationService
    ) {
    }

    public function postPersist(RequestQuestion $question, LifecycleEventArgs $event): void
    {
        $request = $question->getRequest();
        $authorUser = $question->getAuthor();
        $questionText = $question->getQuestionText();
        if (null === $request || null === $authorUser || null === $questionText) {
            $this->logger->warning("❌ No se pudo notificar la pregunta: datos incompletos.");
            return;
        }

        $clientProfile = $request->getClient();
        if (null === $clientProfile) {
            $this->logger->error("❌ Error: El cliente no tiene un perfil de cliente asociado.");
            return;
        }
        
        $clientUser = $clientProfile->getUser();
        if (null === $clientUser) {
            $this->logger->error("❌ Error: El cliente {$clientProfile->getFullName()} no tiene un usuario asociado.");
            return;
        }

        if ($clientProfile->getNotifyRequestActivity() === false) {
            $this->logger->info("🔕 El cliente {$clientProfile->getFullName()} ha desactivado las notificaciones de nuevas preguntas. Saltando...");
            return;
        }

        $title = $request->getTitle() ?? 'tu solicitud';

        try {
            $this->logger->info("🔔 Notificando al cliente {$clientUser->getUserIdentifier()} sobre una nueva pregunta en la solicitud '{$request->getTitle()}'.");
            $this->notificationService->send(
                $clientUser,
                'Duda sobre tu solicitud',
                sprintf(
                    'Un profesional tiene una duda sobre "%s": "%s"',
                    $title,
                    $questionText
                ),
                'QUESTION_RECEIVED',
                $request->getId()
            );
        } catch (\Exception $e) {
            $this->logger->error("❌ Error al enviar la notificación: " . $e->getMessage());
        }
    }

    public function postUpdate(RequestQuestion $question, LifecycleEventArgs $event): void
    {
        $proUser = $question->getAuthor();
        $answerText = $question->getAnswerText();
        $request = $question->getRequest();

        if (null === $answerText || null === $proUser || null === $request) {
            $this->logger->warning("❌ No se pudo notificar la respuesta: datos incompletos.");
            return;
        }

        $proProfile = $proUser->getProfessionalProfile();
        if (null === $proProfile) {
            $this->logger->error("❌ Error: El profesional {$proUser->getUserIdentifier()} no tiene un perfil profesional asociado.");
            return;
        }

        if ($proProfile->getNotifyRequestActivity() === false) {
            $this->logger->info("🔕 El profesional {$proProfile->getFullName()} ha desactivado las notificaciones de respuestas a preguntas. Saltando...");
            return;
        }

        $title = $request->getTitle() ?? 'tu solicitud';

        try {
            $this->logger->info("🔔 Notificando al profesional {$proUser->getUserIdentifier()} sobre la respuesta a su pregunta en la solicitud '{$title}'.");
            
            $this->notificationService->send(
                $proUser,
                'Respuesta a tu pregunta',
                sprintf(
                    'El cliente ha respondido a tu duda en "%s": %s',
                    $title,
                    $answerText
                ),
                'ANSWER_RECEIVED',
                $request->getId()
            );
        } catch (\Exception $e) {
            $this->logger->error("❌ Error al enviar la notificación: " . $e->getMessage());
        }
    }
}