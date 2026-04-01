<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class SupabaseUploadTicketService
{
    private const TICKET_EXPIRES_IN = 300; // 5 minutos

    public function __construct(
        #[Autowire('%env(SUPABASE_URL)%')]
        private readonly string $supabaseUrl,
        #[Autowire('%env(SUPABASE_SERVICE_ROLE_KEY)%')]
        private readonly string $serviceRoleKey,
        #[Autowire('%env(SUPABASE_BUCKET_AVATARS)%')]
        private readonly string $avatarsBucket,
        #[Autowire('%env(SUPABASE_BUCKET_REQUESTS)%')]
        private readonly string $requestsBucket,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Genera un ticket de subida (signed URL) para avatares.
     * El cliente subirá con PUT a signedUrl; después enviará publicUrl a POST /api/users/avatar.
     *
     * @return array{signedUrl: string, publicUrl: string, path: string}
     */
    public function createAvatarUploadTicket(int $userId, string $contentType): array
    {
        $ext = $this->extensionFromContentType($contentType, 'image');
        $pathWithinBucket = sprintf('%d_%s.%s', $userId, uniqid('', true), $ext);

        return $this->createUploadTicket($this->avatarsBucket, $pathWithinBucket);
    }

    /**
     * Genera un ticket de subida para media de requests (photo, audio, video).
     *
     * @param 'photo'|'audio'|'video' $type
     * @return array{signedUrl: string, publicUrl: string, path: string}
     */
    public function createRequestMediaUploadTicket(int $userId, string $type): array
    {
        $ext = match ($type) {
            'photo' => 'jpg',
            'audio' => 'm4a',
            'video' => 'mp4',
            default => 'bin',
        };
        $pathWithinBucket = sprintf('%d_%s_%s.%s', $userId, $type, uniqid('', true), $ext);

        return $this->createUploadTicket($this->requestsBucket, $pathWithinBucket);
    }

    /**
     * @return array{signedUrl: string, publicUrl: string, path: string}
     */
    private function createUploadTicket(string $bucket, string $pathWithinBucket): array
    {
        if ($this->supabaseUrl === '' || $this->serviceRoleKey === '') {
            throw new \RuntimeException(
                'Supabase no está configurado. Añade SUPABASE_URL y SUPABASE_SERVICE_ROLE_KEY en .env.local'
            );
        }

        $baseUrl = rtrim($this->supabaseUrl, '/');
        // Supabase espera: POST /storage/v1/object/upload/sign/{bucket}/{path} con body vacío
        $fullPath = $bucket . '/' . ltrim($pathWithinBucket, '/');
        $endpoint = $baseUrl . '/storage/v1/object/upload/sign/' . $fullPath;

        $response = $this->httpClient->request('POST', $endpoint, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->serviceRoleKey,
                'Content-Type' => 'application/json',
            ],
            'json' => [],
        ]);

        $statusCode = $response->getStatusCode();
        $data = $response->toArray(false);

        if ($statusCode >= 400) {
            $this->logger->error('Supabase upload sign error', [
                'bucket' => $bucket,
                'path' => $pathWithinBucket,
                'status' => $statusCode,
                'response' => $data,
            ]);
            throw new \RuntimeException(
                'No se pudo generar el ticket de subida: ' . ($data['error_description'] ?? $data['message'] ?? $data['error'] ?? 'Error desconocido')
            );
        }

        // La API devuelve "url" como path relativo a /storage/v1, ej: /object/upload/sign/avatars/xxx.jpg?token=...
        $returnedUrl = $data['url'] ?? $data['signedUrl'] ?? $data['signed_url'] ?? null;
        if (!$returnedUrl || !is_string($returnedUrl)) {
            $this->logger->error('Supabase no devolvió URL', ['response' => $data]);
            throw new \RuntimeException('Respuesta inválida de Supabase: no se recibió URL de subida');
        }

        $pathPrefix = str_starts_with($returnedUrl, '/') ? '' : '/';
        $signedUrl = str_starts_with($returnedUrl, 'http')
            ? $returnedUrl
            : $baseUrl . '/storage/v1' . $pathPrefix . $returnedUrl;
        $publicUrl = $baseUrl . '/storage/v1/object/public/' . $bucket . '/' . $pathWithinBucket;

        $this->logger->info('Ticket de subida generado', [
            'bucket' => $bucket,
            'path' => $pathWithinBucket,
        ]);

        return [
            'signedUrl' => $signedUrl,
            'publicUrl' => $publicUrl,
            'path' => $pathWithinBucket,
        ];
    }

    private function extensionFromContentType(string $contentType, string $fallbackType): string
    {
        return match (true) {
            str_contains($contentType, 'jpeg') || str_contains($contentType, 'jpg') => 'jpg',
            str_contains($contentType, 'png') => 'png',
            str_contains($contentType, 'webp') => 'webp',
            str_contains($contentType, 'gif') => 'gif',
            default => $fallbackType === 'image' ? 'jpg' : 'bin',
        };
    }

    /**
     * Elimina un archivo de Supabase cuando recibimos su URL pública
     * (formato: .../storage/v1/object/public/{bucket}/{path}).
     */
    public function deletePublicFileByUrl(string $publicUrl): bool
    {
        if ($publicUrl === '' || $this->supabaseUrl === '' || $this->serviceRoleKey === '') {
            return false;
        }

        $baseUrl = rtrim($this->supabaseUrl, '/');
        $parsed = parse_url($publicUrl);
        if (!is_array($parsed) || !isset($parsed['path'])) {
            return false;
        }

        $path = $parsed['path'];
        $prefix = '/storage/v1/object/public/';
        $pos = strpos($path, $prefix);
        if ($pos === false) {
            return false;
        }

        $relative = substr($path, $pos + strlen($prefix));
        $parts = explode('/', $relative, 2);
        if (count($parts) !== 2) {
            return false;
        }

        [$bucket, $objectPath] = $parts;
        $bucket = trim($bucket);
        $objectPath = ltrim(trim($objectPath), '/');
        if ($bucket === '' || $objectPath === '') {
            return false;
        }

        try {
            $endpoint = sprintf(
                '%s/storage/v1/object/%s/%s',
                $baseUrl,
                rawurlencode($bucket),
                str_replace('%2F', '/', rawurlencode($objectPath))
            );
            $response = $this->httpClient->request('DELETE', $endpoint, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->serviceRoleKey,
                    'apikey' => $this->serviceRoleKey,
                ],
            ]);

            $status = $response->getStatusCode();
            if ($status >= 400) {
                $this->logger->warning('No se pudo borrar archivo en Supabase', [
                    'url' => $publicUrl,
                    'status' => $status,
                ]);
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('Error borrando archivo en Supabase', [
                'url' => $publicUrl,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * @param array<int,string> $publicUrls
     */
    public function deleteManyPublicFiles(array $publicUrls): void
    {
        foreach ($publicUrls as $url) {
            $this->deletePublicFileByUrl($url);
        }
    }
}
