<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Notification;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification as FirebaseNotification;
use Kreait\Firebase\Contract\Messaging;
use Twilio\Rest\Client as TwilioClient;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Psr\Log\LoggerInterface;

final class NotificationService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MailerInterface $mailer,
        private readonly Messaging $messaging,
        private readonly LoggerInterface $logger,
        private readonly string $twilioSid,
        private readonly string $twilioToken,
        private readonly string $twilioFrom
    ) {
    }

    public function send(
        User $recipient,
        string $title,
        string $message,
        string $type,
        ?int $relatedId = null
    ): void {
        $notification = new Notification();
        $notification->setUser($recipient);
        $notification->setTitle($title);
        $notification->setMessage($message);
        $notification->setType($type);
        $notification->setRelatedId($relatedId);

        $this->entityManager->persist($notification);
        $this->entityManager->flush();

        $roles = $recipient->getRoles();
        $sent = false;

        if ($recipient->getClientProfile() || in_array('ROLE_PRO', $roles, true)) {
            $sent = $this->sendWhatsApp($recipient, $message);
        } 
        
        if (!$sent && (in_array('ROLE_PRO', $roles, true) || in_array('ROLE_SOLVER', $roles, true))) {
            $sent = $this->sendPush($recipient, $title, $message, ['relatedId' => (string)$relatedId, 'type' => $type]);
        }

        if (!$sent) {
            $this->sendEmail($recipient, $title, $message);
        }
    }

    private function sendWhatsApp(User $recipient, string $messageBody): bool
    {
        $phone = $recipient->getProfessionalProfile()?->getPhoneNumber() 
              ?? $recipient->getClientProfile()?->getPhoneNumber();

        if (!$phone) {
            $this->logger->info("WhatsApp no enviado: Usuario {$recipient->getId()} no tiene teléfono.");
            return false;
        }

        try {
            $twilio = new TwilioClient($this->twilioSid, $this->twilioToken);
            
            $twilio->messages->create(
                "whatsapp:$phone",
                [
                    "from" => $this->twilioFrom,
                    "body" => $messageBody
                ]
            );
            $this->logger->info("WhatsApp enviado con éxito a $phone");
            return true;
        } catch (\Throwable $e) {
            $this->logger->error("❌ Error enviando WhatsApp a $phone: " . $e->getMessage());
            return false;
        }
    }

    private function sendPush(User $recipient, string $title, string $body, array $data = []): bool
    {
        $token = $recipient->getFcmToken();

        if (!$token) {
            $this->logger->info("Push no enviado: {$recipient->getUserIdentifier()} no tiene FCM Token.");
            return false;
        }

        try {
            $message = CloudMessage::withTarget('token', $token)
                ->withNotification(FirebaseNotification::create($title, $body))
                ->withData($data);

            $this->messaging->send($message);
            return true;
        } catch (\Throwable $e) {
            $this->logger->error("❌ Error Firebase API: " . $e->getMessage());
            return false;
        }
    }

    private function sendEmail(User $recipient, string $subject, string $body): void
    {
        try {
            $email = (new Email())
                ->from('no-reply@quira.app')
                ->to($recipient->getUserIdentifier())
                ->subject($subject)
                ->html("<p>$body</p>");

            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->error("❌ Error Mailer: " . $e->getMessage());
        }
    }
}