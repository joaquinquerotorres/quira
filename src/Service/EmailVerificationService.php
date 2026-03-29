<?php

declare(strict_types=1);

namespace App\Service;

use App\Mail\EmailBranding;
use App\Entity\User;
use App\Entity\VerificationToken;
use App\Repository\VerificationTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

class EmailVerificationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        private readonly EmailBranding $emailBranding,
        #[Autowire('%env(MAILER_DSN)%')]
        private readonly string $mailerDsn,
        private readonly string $frontendUrl
    ) {
    }

    public function sendVerificationEmail(User $user): void
    {
        $this->em->getRepository(VerificationToken::class)
            ->deleteForUser($user, VerificationToken::TYPE_EMAIL);

        $token = bin2hex(random_bytes(32));
        $verificationToken = new VerificationToken();
        $verificationToken->setToken($token);
        $verificationToken->setUser($user);
        $verificationToken->setType(VerificationToken::TYPE_EMAIL);

        $this->em->persist($verificationToken);
        $this->em->flush();

        $confirmUrl = rtrim($this->frontendUrl, '/') . '/verify-email?token=' . $token;

        $email = (new Email())
            ->from('no-reply@quira.app')
            ->to($user->getUserIdentifier())
            ->subject('Verifica tu correo electrónico - Quira')
            ->html($this->buildEmailBody($confirmUrl, $this->emailBranding->headerLogoImgTag()));

        try {
            $this->mailer->send($email);
            if (str_starts_with($this->mailerDsn, 'null://')) {
                $this->logger->warning('Email de verificación: MAILER_DSN es null:// — no se envía correo real. Define MAILER_DSN (p. ej. Brevo) en el servidor.');
            }
            $this->logger->info("Email de verificación procesado para {$user->getUserIdentifier()}");
        } catch (\Throwable $e) {
            $this->logger->error("Error enviando email de verificación: " . $e->getMessage());
            throw $e;
        }
    }

    private function buildEmailBody(string $confirmUrl, string $logo): string
    {
        return <<<HTML
        <!DOCTYPE html>
        <html lang="es">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Verifica tu email - Quira</title>
        </head>
        <body style="margin:0;padding:0;background-color:#f4f6f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;">
            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color:#f4f6f9;padding:40px 20px;">
                <tr>
                    <td align="center">
                        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="max-width:480px;background-color:#ffffff;border-radius:16px;border:1px solid #e2e8f0;box-shadow:0 10px 30px rgba(15,23,42,0.05);overflow:hidden;">
                            <tr>
                                <td style="background-color:#a09ce2;padding:32px;text-align:center;color:#ffffff;">
                                    {$logo}
                                    <p style="margin:12px 0 0;font-size:14px;color:rgba(255,255,255,0.9);">Conectando clientes y profesionales</p>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:40px 32px;">
                                    <h1 style="margin:0 0 16px;font-size:24px;font-weight:600;color:#1f2937;line-height:1.3;">Verifica tu correo electrónico</h1>
                                    <p style="margin:0 0 24px;font-size:16px;color:#4b5563;line-height:1.6;">Gracias por registrarte en Quira. Para activar tu cuenta y empezar a conectar con clientes o profesionales, confirma tu correo haciendo clic en el botón:</p>
                                    <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 24px;">
                                        <tr>
                                            <td style="border-radius:999px;background-color:#f97316;text-align:center;">
                                                <a href="{$confirmUrl}" target="_blank" style="display:inline-block;padding:14px 32px;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:999px;">Verificar mi correo</a>
                                            </td>
                                        </tr>
                                    </table>
                                    <p style="margin:0;font-size:14px;color:#6b7280;line-height:1.5;">Si el botón no funciona, copia y pega este enlace en tu navegador:</p>
                                    <p style="margin:8px 0 0;font-size:12px;color:#14b8a6;word-break:break-all;">{$confirmUrl}</p>
                                    <hr style="margin:28px 0;border:none;border-top:1px solid #e5e7eb;">
                                    <p style="margin:0;font-size:13px;color:#9ca3af;">Si no creaste una cuenta en Quira, puedes ignorar este mensaje de forma segura.</p>
                                    <p style="margin:12px 0 0;font-size:12px;color:#9ca3af;">El enlace caduca en 24 horas.</p>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:24px 32px;background-color:#f9fafb;border-top:1px solid #e5e7eb;">
                                    <p style="margin:0;font-size:12px;color:#9ca3af;text-align:center;">© Quira · Conectando servicios profesionales</p>
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
}
