<?php

namespace App\Service;

use Kreait\Firebase\Contract\Storage;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class FirebaseUploaderService
{
    public function __construct(
        #[Autowire('%env(FIREBASE_STORAGE_BUCKET)%')] 
        private string $bucketName,
        private readonly LoggerInterface $logger,
        private readonly Storage $storage,
        
    ) {}

    public function uploadBase64(?string $base64String, string $folder): ?string
    {
        if (!$base64String) {
            $this->logger->warning('No se proporcionó una cadena base64 para subir a Firebase Storage.');
            return null;
        }

        if (!preg_match('/^data:([a-z0-9]+\/[a-z0-9]+);base64,/', $base64String, $matches)) {
            return null;
        }

        $mimeType = $matches[1];
        $data = base64_decode(substr($base64String, strpos($base64String, ',') + 1));
        
        if ($data === false) {
            $this->logger->error('❌ Error al decodificar la cadena base64 para subir a Firebase Storage.');
            return null;
        }

        $extension = $this->getExtension($mimeType);
        $filename = uniqid() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
        $path = $folder . '/' . $filename;

        $bucket = $this->storage->getBucket($this->bucketName);
        
        $bucket->upload($data, [
            'name' => $path,
            'predefinedAcl' => 'publicRead', 
            'metadata' => [
                'contentType' => $mimeType,
            ]
        ]);

        $this->logger->info("Archivo subido a Firebase Storage: {$path} con MIME type {$mimeType}.");
        return sprintf('https://storage.googleapis.com/%s/%s', $this->bucketName, $path);
    }

    private function getExtension(string $mimeType): string
    {
        return match ($mimeType) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'audio/mpeg', 'audio/mp3' => 'mp3',
            'audio/aac', 'audio/x-aac', 'audio/mp4' => 'aac', 
            'video/mp4' => 'mp4',
            'video/quicktime' => 'mov', 
            default => 'bin',
        };
    }
}