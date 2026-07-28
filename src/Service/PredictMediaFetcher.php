<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Descarga media desde URLs públicas de Supabase (anti-SSRF) y las convierte
 * a Data URL para GeminiService::diagnose (inline_data).
 */
final class PredictMediaFetcher
{
    private const MAX_IMAGE_BYTES = 10_000_000;
    private const MAX_AUDIO_BYTES = 12_000_000;
    private const MAX_VIDEO_BYTES = 40_000_000;

    public function __construct(
        #[Autowire('%env(SUPABASE_URL)%')]
        private readonly string $supabaseUrl,
        #[Autowire('%env(SUPABASE_BUCKET_REQUESTS)%')]
        private readonly string $requestsBucket,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return string Data URL (data:{mime};base64,...)
     */
    public function fetchAsDataUrl(string $publicUrl, string $kind): string
    {
        $this->assertAllowedPublicUrl($publicUrl);

        $maxBytes = match ($kind) {
            'image' => self::MAX_IMAGE_BYTES,
            'audio' => self::MAX_AUDIO_BYTES,
            'video' => self::MAX_VIDEO_BYTES,
            default => self::MAX_IMAGE_BYTES,
        };

        try {
            $response = $this->httpClient->request('GET', $publicUrl, [
                'timeout' => 120,
                'max_duration' => 120,
            ]);
            $status = $response->getStatusCode();
            if ($status >= 400) {
                throw new \RuntimeException(sprintf('No se pudo descargar el archivo (%d).', $status));
            }

            $headers = $response->getHeaders(false);
            $contentType = $headers['content-type'][0] ?? null;
            $binary = $response->getContent(false);
        } catch (\Throwable $e) {
            $this->logger->error('PredictMediaFetcher download failed', [
                'url' => $publicUrl,
                'kind' => $kind,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException('No se pudo descargar el archivo multimedia para el análisis.');
        }

        $size = strlen($binary);
        if ($size === 0) {
            throw new \RuntimeException('El archivo multimedia está vacío.');
        }
        if ($size > $maxBytes) {
            throw new \RuntimeException(sprintf(
                'El archivo es demasiado grande para analizar (%s, máx. %d MB).',
                $kind,
                (int) floor($maxBytes / 1_000_000)
            ));
        }

        $mime = $this->normalizeMime(
            is_string($contentType) ? $contentType : null,
            $kind,
            $binary
        );

        return 'data:' . $mime . ';base64,' . base64_encode($binary);
    }

    public function assertAllowedPublicUrl(string $publicUrl): void
    {
        $base = rtrim($this->supabaseUrl, '/');
        if ($base === '' || $this->requestsBucket === '') {
            throw new \RuntimeException('Almacenamiento no configurado para analizar por URL.');
        }

        $allowedPrefix = $base . '/storage/v1/object/public/' . $this->requestsBucket . '/';
        if (!str_starts_with($publicUrl, $allowedPrefix)) {
            $this->logger->warning('PredictMediaFetcher rejected URL (SSRF guard)', [
                'url' => $publicUrl,
                'allowedPrefix' => $allowedPrefix,
            ]);
            throw new \RuntimeException('URL de media no permitida.');
        }

        $parts = parse_url($publicUrl);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https') {
            throw new \RuntimeException('URL de media no válida.');
        }
    }

    private function normalizeMime(?string $contentType, string $kind, string $binary): string
    {
        $mime = $contentType !== null ? strtolower(trim(explode(';', $contentType)[0])) : '';

        if ($mime === '' || $mime === 'application/octet-stream') {
            $mime = match ($kind) {
                'audio' => $this->sniffAudioMime($binary) ?? 'audio/mp4',
                'video' => str_starts_with($binary, "\x1a\x45\xdf\xa3") ? 'video/webm' : 'video/mp4',
                default => 'image/jpeg',
            };
        }

        if ($mime === 'audio/mp3') {
            $mime = 'audio/mpeg';
        }
        if ($mime === 'audio/m4a') {
            $mime = 'audio/mp4';
        }

        return $mime;
    }

    private function sniffAudioMime(string $binary): ?string
    {
        if (str_starts_with($binary, "\x1a\x45\xdf\xa3")) {
            return 'audio/webm';
        }
        if (str_starts_with($binary, 'ID3') || (strlen($binary) > 1 && (ord($binary[0]) & 0xFF) === 0xFF)) {
            return 'audio/mpeg';
        }
        if (str_starts_with($binary, 'RIFF') && str_contains(substr($binary, 0, 16), 'WAVE')) {
            return 'audio/wav';
        }
        if (str_starts_with($binary, 'ftyp') || (strlen($binary) > 8 && substr($binary, 4, 4) === 'ftyp')) {
            return 'audio/mp4';
        }

        return null;
    }
}
