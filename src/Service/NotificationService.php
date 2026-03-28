<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Notification;
use App\Entity\User;
use App\Enum\NotificationAudience;
use Doctrine\ORM\EntityManagerInterface;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification as FirebaseNotification;
use Kreait\Firebase\Contract\Messaging;
use Twilio\Rest\Client as TwilioClient;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Psr\Log\LoggerInterface;

/**
 * Canales vía env: NOTIFICATIONS_PRO, NOTIFICATIONS_SOLVER, NOTIFICATIONS_FREE, NOTIFICATIONS_CLIENT.
 * Valores: PUSH, EMAIL, WHATSAPP. Fallback: WHATSAPP→push→email; PUSH→email; EMAIL→push (WhatsApp nunca como respaldo).
 *
 * {@see NotificationAudience}: mismo User puede ser cliente y profesional; el caller indica la faceta para elegir
 * NOTIFICATIONS_CLIENT vs NOTIFICATIONS_FREE/PRO/SOLVER (no se infiere solo por roles).
 */
final class NotificationService
{
    private const CHANNEL_PUSH = 'PUSH';
    private const CHANNEL_EMAIL = 'EMAIL';
    private const CHANNEL_WHATSAPP = 'WHATSAPP';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MailerInterface $mailer,
        private readonly Messaging $messaging,
        private readonly LoggerInterface $logger,
        private readonly string $twilioSid,
        private readonly string $twilioToken,
        private readonly string $twilioFrom,
        private readonly string $notificationsPro,
        private readonly string $notificationsSolver,
        private readonly string $notificationsFree,
        private readonly string $notificationsClient,
    ) {
    }

    public function send(
        User $recipient,
        string $title,
        string $message,
        string $type,
        NotificationAudience $audience,
        ?int $relatedId = null,
    ): void {
        $notification = new Notification();
        $notification->setUser($recipient);
        $notification->setTitle($title);
        $notification->setMessage($message);
        $notification->setType($type);
        $notification->setRelatedId($relatedId);

        $this->entityManager->persist($notification);
        $this->entityManager->flush();

        $data = ['relatedId' => (string) $relatedId, 'type' => $type];
        $channel = $this->resolveConfiguredChannel($recipient, $audience);
        $this->deliverByChannel($channel, $recipient, $title, $message, $data);
    }

    private function resolveConfiguredChannel(User $user, NotificationAudience $audience): string
    {
        if ($audience === NotificationAudience::Client) {
            return $this->normalizeChannelString($this->notificationsClient, self::CHANNEL_PUSH);
        }

        $roles = $user->getRoles();

        if (in_array('ROLE_PRO', $roles, true)) {
            return $this->normalizeChannelString($this->notificationsPro, self::CHANNEL_PUSH);
        }
        if (in_array('ROLE_SOLVER', $roles, true)) {
            return $this->normalizeChannelString($this->notificationsSolver, self::CHANNEL_PUSH);
        }
        if (in_array('ROLE_FREE', $roles, true)) {
            return $this->normalizeChannelString($this->notificationsFree, self::CHANNEL_EMAIL);
        }

        if ($user->getProfessionalProfile() !== null) {
            return $this->normalizeChannelString($this->notificationsFree, self::CHANNEL_EMAIL);
        }

        if ($user->getClientProfile() !== null) {
            return $this->normalizeChannelString($this->notificationsClient, self::CHANNEL_PUSH);
        }

        return $this->normalizeChannelString($this->notificationsClient, self::CHANNEL_PUSH);
    }

    private function normalizeChannelString(string $raw, string $default): string
    {
        $v = strtoupper(trim($raw));
        if (in_array($v, [self::CHANNEL_PUSH, self::CHANNEL_EMAIL, self::CHANNEL_WHATSAPP], true)) {
            return $v;
        }
        if ($v !== '') {
            $this->logger->warning("Canal de notificación desconocido '{$raw}', se usa {$default}.");
        }

        return $default;
    }

    private function deliverByChannel(
        string $channel,
        User $recipient,
        string $title,
        string $message,
        array $data
    ): void {
        // WhatsApp es de pago: solo se usa si el canal configurado es WHATSAPP, nunca como fallback.
        match ($channel) {
            self::CHANNEL_WHATSAPP => $this->sendWhatsApp($recipient, $message)
                || $this->sendPush($recipient, $title, $message, $data)
                || $this->sendEmail($recipient, $title, $message),
            self::CHANNEL_PUSH => $this->sendPush($recipient, $title, $message, $data)
                || $this->sendEmail($recipient, $title, $message),
            self::CHANNEL_EMAIL => $this->sendEmail($recipient, $title, $message)
                || $this->sendPush($recipient, $title, $message, $data),
            default => $this->sendEmail($recipient, $title, $message)
                || $this->sendPush($recipient, $title, $message, $data),
        };
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
                    'from' => $this->twilioFrom,
                    'body' => $messageBody,
                ]
            );
            $this->logger->info("WhatsApp enviado con éxito a $phone");
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('❌ Error enviando WhatsApp a '.$phone.': '.$e->getMessage());
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
            $this->logger->error('❌ Error Firebase API: '.$e->getMessage());
            return false;
        }
    }

    private function sendEmail(User $recipient, string $subject, string $body): bool
    {
        try {
            $email = (new Email())
                ->from('no-reply@quira.app')
                ->to($recipient->getUserIdentifier())
                ->subject($subject)
                ->html("<p>$body</p>");

            $this->mailer->send($email);
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('❌ Error Mailer: '.$e->getMessage());
            return false;
        }
    }
}
