<?php

declare(strict_types=1);

namespace App\Service;

use App\Mail\EmailBranding;
use App\Entity\User;
use App\Entity\VerificationToken;
use App\Repository\UserRepository;
use App\Repository\VerificationTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class PasswordResetService
{
    private const TOKEN_EXPIRY = '+1 hour';

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly VerificationTokenRepository $tokenRepository,
        private readonly EntityManagerInterface $em,
        private readonly MailerInterface $mailer,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly LoggerInterface $logger,
        private readonly EmailBranding $emailBranding,
        private readonly string $frontendUrl
    ) {
    }

    /**
     * Envía un correo con enlace de recuperación si el usuario existe.
     * Siempre devuelve éxito para no revelar si el email está registrado.
     */
    public function sendResetEmail(string $email): void
    {
        $user = $this->userRepository->findOneBy(['email' => $email]);
        if (!$user) {
            $this->logger->info("Solicitud de recuperación de contraseña para email no registrado: {$email}");
            return;
        }

        $this->tokenRepository->deleteForUser($user, VerificationToken::TYPE_PASSWORD_RESET);

        $token = bin2hex(random_bytes(32));
        $verificationToken = new VerificationToken();
        $verificationToken->setToken($token);
        $verificationToken->setUser($user);
        $verificationToken->setType(VerificationToken::TYPE_PASSWORD_RESET);
        $verificationToken->setExpiresAt(new \DateTimeImmutable(self::TOKEN_EXPIRY));

        $this->em->persist($verificationToken);
        $this->em->flush();

        $resetUrl = rtrim($this->frontendUrl, '/') . '/reset-password?token=' . $token;

        $emailMessage = (new Email())
            ->from('no-reply@quira.app')
            ->to($user->getUserIdentifier())
            ->subject('Recupera tu contraseña - Quira')
            ->html($this->buildEmailBody($resetUrl, $this->emailBranding->headerLogoImgTag()));

        try {
            $this->mailer->send($emailMessage);
            $this->logger->info("Email de recuperación de contraseña enviado a {$user->getUserIdentifier()}");
        } catch (\Throwable $e) {
            $this->logger->error("Error enviando email de recuperación: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Actualiza la contraseña del usuario si el token es válido.
     */
    public function resetPassword(string $token, string $newPassword): bool
    {
        $verificationToken = $this->tokenRepository->findValidByToken($token, VerificationToken::TYPE_PASSWORD_RESET);
        if (!$verificationToken) {
            return false;
        }

        $user = $verificationToken->getUser();
        $user->setPassword($this->passwordHasher->hashPassword($user, $newPassword));
        $this->em->remove($verificationToken);
        $this->em->flush();

        $this->logger->info("Contraseña restablecida para usuario {$user->getUserIdentifier()}");

        return true;
    }

    private function buildEmailBody(string $resetUrl, string $logo): string
    {
        return <<<HTML
        <!DOCTYPE html>
        <html lang="es">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Recupera tu contraseña - Quira</title>
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
                                    <h1 style="margin:0 0 16px;font-size:24px;font-weight:600;color:#1f2937;line-height:1.3;">Recupera tu contraseña</h1>
                                    <p style="margin:0 0 24px;font-size:16px;color:#4b5563;line-height:1.6;">Hemos recibido una solicitud para restablecer la contraseña de tu cuenta. Haz clic en el botón para elegir una nueva:</p>
                                    <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 24px;">
                                        <tr>
                                            <td style="border-radius:999px;background-color:#f97316;text-align:center;">
                                                <a href="{$resetUrl}" target="_blank" style="display:inline-block;padding:14px 32px;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:999px;">Restablecer contraseña</a>
                                            </td>
                                        </tr>
                                    </table>
                                    <p style="margin:0;font-size:14px;color:#6b7280;line-height:1.5;">Si el botón no funciona, copia y pega este enlace en tu navegador:</p>
                                    <p style="margin:8px 0 0;font-size:12px;color:#14b8a6;word-break:break-all;">{$resetUrl}</p>
                                    <hr style="margin:28px 0;border:none;border-top:1px solid #e5e7eb;">
                                    <p style="margin:0;font-size:13px;color:#9ca3af;">Si no solicitaste restablecer tu contraseña, ignora este correo. Tu contraseña actual no cambiará.</p>
                                    <p style="margin:12px 0 0;font-size:12px;color:#9ca3af;">El enlace caduca en 1 hora.</p>
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
