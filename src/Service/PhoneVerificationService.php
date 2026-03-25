<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Twilio\Rest\Client as TwilioClient;

final class PhoneVerificationService
{
    private const OTP_TTL = 300; // 5 minutos
    private const OTP_LENGTH = 6;

    public function __construct(
        private readonly string $twilioSid,
        private readonly string $twilioToken,
        private readonly string $twilioSmsFrom,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger
    ) {
    }

    public function normalizePhone(string $phone): string
    {
        return $this->doNormalizePhone($phone);
    }

    public function sendOtp(string $phoneNumber, User $user): void
    {
        $normalized = $this->doNormalizePhone($phoneNumber);
        $hash = hash('sha256', $normalized . '_' . $user->getId());
        $cacheKey = 'otp_' . $hash;
        $sentKey = 'otp_sent_' . $hash;

        $alreadySent = $this->cache->get($sentKey, function (ItemInterface $item) {
            $item->expiresAfter(self::OTP_TTL);
            return 0;
        });
        if ($alreadySent === 1) {
            return;
        }

        $code = $this->cache->get($cacheKey, function (ItemInterface $item) {
            $item->expiresAfter(self::OTP_TTL);
            return $this->generateOtp();
        });

        $this->sendSms($normalized, $code);
        $this->cache->delete($sentKey);
        $this->cache->get($sentKey, function (ItemInterface $item) {
            $item->expiresAfter(self::OTP_TTL);
            return 1;
        });
        $this->logger->info(
            $this->twilioSmsFrom !== ''
                ? "OTP enviado a $normalized para usuario {$user->getId()}"
                : "OTP (sandbox) para $normalized: $code — usa este código en /verify/phone/confirm"
        );
    }

    public function verifyOtp(string $phoneNumber, string $code, User $user): bool
    {
        $normalized = $this->doNormalizePhone($phoneNumber);
        $hash = hash('sha256', $normalized . '_' . $user->getId());
        $cacheKey = 'otp_' . $hash;
        $sentKey = 'otp_sent_' . $hash;

        $stored = $this->cache->get($cacheKey, fn () => null);
        if ($stored === null || $stored !== $code) {
            return false;
        }

        $this->cache->delete($cacheKey);
        $this->cache->delete($sentKey);
        return true;
    }

    private function sendSms(string $to, string $code): void
    {
        if ($this->twilioSmsFrom === '') {
            $this->logger->warning("OTP (sandbox) para {$to}: {$code} — Twilio no configurado (TWILIO_SMS_FROM vacío). Usa este código en POST /api/verify/phone/confirm.");
            return;
        }

        $twilio = new TwilioClient($this->twilioSid, $this->twilioToken);
        $twilio->messages->create($to, [
            'from' => $this->twilioSmsFrom,
            'body' => "Tu código de verificación Quira es: $code. Válido durante 5 minutos.",
        ]);
    }

    private function generateOtp(): string
    {
        $digits = '';
        for ($i = 0; $i < self::OTP_LENGTH; $i++) {
            $digits .= (string) random_int(0, 9);
        }
        return $digits;
    }

    private function doNormalizePhone(string $phone): string
    {
        $phone = preg_replace('/\D/', '', $phone);
        if (!str_starts_with($phone, '34') && strlen($phone) === 9) {
            $phone = '34' . $phone;
        }
        return '+' . $phone;
    }
}
