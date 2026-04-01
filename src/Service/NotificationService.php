<?php

declare(strict_types=1);

namespace App\Service;

use App\Mail\EmailBranding;
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
        private readonly EmailBranding $emailBranding,
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
        array $emailContext = [],
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
        $this->deliverByChannel($channel, $recipient, $title, $message, $type, $data, $emailContext);
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
        string $type,
        array $data,
        array $emailContext = [],
    ): void {
        // WhatsApp es de pago: solo se usa si el canal configurado es WHATSAPP, nunca como fallback.
        match ($channel) {
            self::CHANNEL_WHATSAPP => $this->sendWhatsApp($recipient, $message)
                || $this->sendPush($recipient, $title, $message, $data)
                || $this->sendEmail($recipient, $title, $message, $type, $emailContext),
            self::CHANNEL_PUSH => $this->sendPush($recipient, $title, $message, $data)
                || $this->sendEmail($recipient, $title, $message, $type, $emailContext),
            self::CHANNEL_EMAIL => $this->sendEmail($recipient, $title, $message, $type, $emailContext)
                || $this->sendPush($recipient, $title, $message, $data),
            default => $this->sendEmail($recipient, $title, $message, $type, $emailContext)
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

    private function sendEmail(User $recipient, string $subject, string $body, string $type, array $emailContext = []): bool
    {
        try {
            $html = $this->buildEmailHtml($recipient, $body, $type, $emailContext);
            $email = (new Email())
                ->from('no-reply@quira.app')
                ->to($recipient->getUserIdentifier())
                ->subject($subject)
                ->html($html);

            $this->mailer->send($email);
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('❌ Error Mailer: '.$e->getMessage());
            return false;
        }
    }

    private function buildEmailHtml(User $recipient, string $body, string $type, array $emailContext = []): string
    {
        if ($type === 'BID_RECEIVED') {
            $proName = (string) ($emailContext['professionalName'] ?? '');
            $amount = (string) ($emailContext['amount'] ?? '');
            $requestTitle = (string) ($emailContext['requestTitle'] ?? '');

            if ($proName !== '' && $amount !== '' && $requestTitle !== '') {
                $recipientName = $recipient->getClientProfile()?->getFullName() ?? '';
                return $this->buildOfferEmailHtml(
                    profesionalName: $proName,
                    amount: $amount,
                    requestTitle: $requestTitle,
                    recipientName: $recipientName,
                    logoImgTag: $this->emailBranding->headerLogoImgTag(),
                );
            }
        }

        if ($type === 'NEW_REQUEST') {
            $requestTitle = (string) ($emailContext['requestTitle'] ?? '');
            $category = (string) ($emailContext['category'] ?? '');
            $priceRange = (string) ($emailContext['priceRange'] ?? '');

            if ($requestTitle !== '' && $category !== '' && $priceRange !== '') {
                $recipientName = $recipient->getProfessionalProfile()?->getFullName() ?? '';
                return $this->buildNewRequestEmailHtml(
                    requestTitle: $requestTitle,
                    category: $category,
                    priceRange: $priceRange,
                    recipientName: $recipientName,
                    logoImgTag: $this->emailBranding->headerLogoImgTag(),
                );
            }
        }

        return $this->buildGenericNotificationEmailHtml(
            title: $this->resolveGenericEmailTitle($type),
            message: $body,
            recipientName: $this->resolveRecipientName($recipient),
            logoImgTag: $this->emailBranding->headerLogoImgTag(),
        );
    }

    private function buildOfferEmailHtml(
        string $profesionalName,
        string $amount,
        string $requestTitle,
        string $recipientName = '',
        string $logoImgTag = ''
    ): string {
        $safePro = htmlspecialchars($profesionalName, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $safeTitle = htmlspecialchars($requestTitle, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $safeAmount = htmlspecialchars($amount, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $safeRecip = $recipientName !== '' ? htmlspecialchars($recipientName, ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
        $greeting = $safeRecip !== '' ? "Hola, {$safeRecip}" : 'Hola';

        return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Nueva oferta recibida</title>
</head>
<body style="margin:0;padding:0;background:#f0f0ff;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f0f0ff;padding:40px 16px;">
    <tr>
      <td align="center">
        <table width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;">
          <tr>
            <td style="background:#1e1b4b;border-radius:16px 16px 0 0;padding:28px 36px;text-align:center;">
              {$logoImgTag}
              <div style="margin-top:12px;">
                <span style="font-family:Georgia,serif;font-size:22px;font-weight:900;color:#ffffff;letter-spacing:-0.5px;">
                  Qu<span style="color:#f97316;">i</span>r<span style="color:#f97316;">a</span>
                </span>
              </div>
            </td>
          </tr>
          <tr>
            <td style="background:#ffffff;padding:36px 36px 28px;border-left:1px solid #e0e7ff;border-right:1px solid #e0e7ff;">
              <p style="margin:0 0 24px;font-size:15px;color:#6b7280;font-weight:600;">{$greeting} 👋</p>
              <p style="margin:0 0 28px;font-size:16px;color:#1e1b4b;line-height:1.6;">
                <strong style="color:#1e1b4b;">{$safePro}</strong> ha enviado una oferta para tu solicitud:
              </p>
              <div style="background:#f9f8ff;border:1.5px solid #e0e7ff;border-radius:12px;padding:16px 20px;margin-bottom:24px;">
                <p style="margin:0;font-size:13px;font-weight:700;color:#6366f1;text-transform:uppercase;letter-spacing:1px;margin-bottom:6px;">Solicitud</p>
                <p style="margin:0;font-size:16px;font-weight:800;color:#1e1b4b;">{$safeTitle}</p>
              </div>
              <div style="background:linear-gradient(135deg,#4f46e5,#6366f1);border-radius:12px;padding:24px;text-align:center;margin-bottom:28px;">
                <p style="margin:0 0 4px;font-size:13px;color:rgba(255,255,255,0.7);font-weight:600;text-transform:uppercase;letter-spacing:1px;">Oferta recibida</p>
                <p style="margin:0;font-size:42px;font-weight:900;color:#ffffff;letter-spacing:-2px;font-family:Georgia,serif;">
                  {$safeAmount}<span style="font-size:22px;">€</span>
                </p>
              </div>
              <p style="margin:8px 0 0;font-size:13px;color:#9ca3af;line-height:1.6;text-align:center;">
                Puedes negociar directamente desde la app.<br>
                <strong style="color:#f97316;">¡Entra a verla!</strong>
              </p>
            </td>
          </tr>
          <tr>
            <td style="background:#1e1b4b;border-radius:0 0 16px 16px;padding:20px 36px;text-align:center;">
              <p style="margin:0;font-size:12px;color:rgba(255,255,255,0.4);line-height:1.6;">
                Has recibido este correo porque tienes una cuenta en Quira.<br>
                <a href="https://quira.app/privacidad" style="color:#f97316;text-decoration:none;">Política de privacidad</a>
                &nbsp;·&nbsp;
                <a href="mailto:hola@quira.app" style="color:rgba(255,255,255,0.4);text-decoration:none;">Contacto</a>
              </p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
    }

    private function buildNewRequestEmailHtml(
        string $requestTitle,
        string $category,
        string $priceRange,
        string $recipientName = '',
        string $logoImgTag = ''
    ): string {
        $safeTitle = htmlspecialchars($requestTitle, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $safeCategory = htmlspecialchars($category, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $safePrice = htmlspecialchars($priceRange, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $safeRecip = $recipientName !== '' ? htmlspecialchars($recipientName, ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
        $greeting = $safeRecip !== '' ? "Hola, {$safeRecip}" : 'Hola';

        return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Nueva solicitud cerca de ti</title>
</head>
<body style="margin:0;padding:0;background:#f0f0ff;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f0f0ff;padding:40px 16px;">
    <tr>
      <td align="center">
        <table width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;">
          <tr>
            <td style="background:#1e1b4b;border-radius:16px 16px 0 0;padding:28px 36px;text-align:center;">
              {$logoImgTag}
              <div style="margin-top:12px;">
                <span style="font-family:Georgia,serif;font-size:22px;font-weight:900;color:#ffffff;letter-spacing:-0.5px;">
                  Qu<span style="color:#f97316;">i</span>r<span style="color:#f97316;">a</span>
                </span>
              </div>
            </td>
          </tr>
          <tr>
            <td style="background:#ffffff;padding:36px 36px 28px;border-left:1px solid #e0e7ff;border-right:1px solid #e0e7ff;">
              <p style="margin:0 0 24px;font-size:15px;color:#6b7280;font-weight:600;">{$greeting} 👋</p>
              <p style="margin:0 0 22px;font-size:16px;color:#1e1b4b;line-height:1.6;">
                Ha aparecido una <strong style="color:#1e1b4b;">nueva solicitud</strong> que encaja con tu perfil profesional:
              </p>

              <div style="background:#f9f8ff;border:1.5px solid #e0e7ff;border-radius:12px;padding:16px 20px;margin-bottom:16px;">
                <p style="margin:0;font-size:13px;font-weight:700;color:#6366f1;text-transform:uppercase;letter-spacing:1px;margin-bottom:6px;">Solicitud</p>
                <p style="margin:0;font-size:16px;font-weight:800;color:#1e1b4b;">{$safeTitle}</p>
              </div>

              <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:22px;">
                <tr>
                  <td style="width:50%;padding-right:6px;">
                    <div style="background:#eef2ff;border-radius:10px;padding:12px;text-align:center;">
                      <p style="margin:0 0 4px;font-size:12px;color:#6366f1;font-weight:700;text-transform:uppercase;letter-spacing:.8px;">Categoría</p>
                      <p style="margin:0;font-size:15px;font-weight:800;color:#1e1b4b;">{$safeCategory}</p>
                    </div>
                  </td>
                  <td style="width:50%;padding-left:6px;">
                    <div style="background:#fff7ed;border-radius:10px;padding:12px;text-align:center;">
                      <p style="margin:0 0 4px;font-size:12px;color:#ea580c;font-weight:700;text-transform:uppercase;letter-spacing:.8px;">Rango estimado</p>
                      <p style="margin:0;font-size:15px;font-weight:800;color:#7c2d12;">{$safePrice}</p>
                    </div>
                  </td>
                </tr>
              </table>

              <p style="margin:8px 0 0;font-size:13px;color:#9ca3af;line-height:1.6;text-align:center;">
                Revisa la solicitud y envía tu propuesta cuanto antes.<br>
                <strong style="color:#f97316;">¡Las primeras ofertas suelen tener más conversión!</strong>
              </p>
            </td>
          </tr>
          <tr>
            <td style="background:#1e1b4b;border-radius:0 0 16px 16px;padding:20px 36px;text-align:center;">
              <p style="margin:0;font-size:12px;color:rgba(255,255,255,0.4);line-height:1.6;">
                Has recibido este correo porque tienes una cuenta en Quira.<br>
                <a href="https://quira.app/privacidad" style="color:#f97316;text-decoration:none;">Política de privacidad</a>
                &nbsp;·&nbsp;
                <a href="mailto:hola@quira.app" style="color:rgba(255,255,255,0.4);text-decoration:none;">Contacto</a>
              </p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
    }

    private function buildGenericNotificationEmailHtml(
        string $title,
        string $message,
        string $recipientName = '',
        string $logoImgTag = ''
    ): string {
        $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $safeMessage = htmlspecialchars($message, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $safeRecip = $recipientName !== '' ? htmlspecialchars($recipientName, ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
        $greeting = $safeRecip !== '' ? "Hola, {$safeRecip}" : 'Hola';

        return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>{$safeTitle}</title>
</head>
<body style="margin:0;padding:0;background:#f0f0ff;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f0f0ff;padding:40px 16px;">
    <tr>
      <td align="center">
        <table width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;">
          <tr>
            <td style="background:#1e1b4b;border-radius:16px 16px 0 0;padding:28px 36px;text-align:center;">
              {$logoImgTag}
              <div style="margin-top:12px;">
                <span style="font-family:Georgia,serif;font-size:22px;font-weight:900;color:#ffffff;letter-spacing:-0.5px;">
                  Qu<span style="color:#f97316;">i</span>r<span style="color:#f97316;">a</span>
                </span>
              </div>
            </td>
          </tr>
          <tr>
            <td style="background:#ffffff;padding:36px 36px 28px;border-left:1px solid #e0e7ff;border-right:1px solid #e0e7ff;">
              <p style="margin:0 0 24px;font-size:15px;color:#6b7280;font-weight:600;">{$greeting} 👋</p>
              <p style="margin:0 0 14px;font-size:20px;color:#1e1b4b;font-weight:900;letter-spacing:-.2px;">{$safeTitle}</p>
              <div style="background:#f9f8ff;border:1.5px solid #e0e7ff;border-radius:12px;padding:16px 20px;margin-bottom:20px;">
                <p style="margin:0;font-size:15px;color:#1e1b4b;line-height:1.65;">{$safeMessage}</p>
              </div>
              <p style="margin:8px 0 0;font-size:13px;color:#9ca3af;line-height:1.6;text-align:center;">
                Revisa la notificación en la app para ver todos los detalles.
              </p>
            </td>
          </tr>
          <tr>
            <td style="background:#1e1b4b;border-radius:0 0 16px 16px;padding:20px 36px;text-align:center;">
              <p style="margin:0;font-size:12px;color:rgba(255,255,255,0.4);line-height:1.6;">
                Has recibido este correo porque tienes una cuenta en Quira.<br>
                <a href="https://quira.app/privacidad" style="color:#f97316;text-decoration:none;">Política de privacidad</a>
                &nbsp;·&nbsp;
                <a href="mailto:hola@quira.app" style="color:rgba(255,255,255,0.4);text-decoration:none;">Contacto</a>
              </p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
    }

    private function resolveGenericEmailTitle(string $type): string
    {
        return match ($type) {
            'BID_ACCEPTED' => '¡Tu oferta ha sido aceptada!',
            'NEW_QUESTION' => 'Nueva pregunta en una solicitud',
            'QUESTION_ANSWERED' => 'Han respondido a tu pregunta',
            'VISIT_REQUEST_CREATED' => 'Nueva solicitud de visita',
            'VISIT_REQUEST_ACCEPTED' => 'Solicitud de visita aceptada',
            'VISIT_REQUEST_REJECTED' => 'Solicitud de visita rechazada',
            'REVIEW_RECEIVED' => 'Has recibido una nueva reseña',
            default => 'Nueva notificación',
        };
    }

    private function resolveRecipientName(User $recipient): string
    {
        return $recipient->getClientProfile()?->getFullName()
            ?? $recipient->getProfessionalProfile()?->getFullName()
            ?? '';
    }
}
