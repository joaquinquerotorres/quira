<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

class MediaService
{
    public function __construct(
        #[Autowire('%requests_uploads_directory%')]
        private string $targetDirectory,
        private readonly LoggerInterface $logger,
        private readonly SluggerInterface $slugger,
        private readonly Filesystem $filesystem,
    ) {
        if (!$this->filesystem->exists($this->targetDirectory)) {
            $this->filesystem->mkdir($this->targetDirectory);
        }
    }

    public function saveRequestMediaFile(string $base64String, string $type, string $folder, bool $saveInCloud = false): string
    {
        if ($saveInCloud) {
            throw new \RuntimeException('Cloud upload no soportado. Usa Supabase: photoUrl, audioUrl, videoUrl.');
        }

        if (str_contains($base64String, ',')) {
            $base64String = explode(',', $base64String)[1];
        }

        $fileData = base64_decode($base64String);

        if ($fileData === false) {
            throw new \Exception("Invalid base64 for $type");
        }

        $extension = match ($type) {
            'image' => 'jpg',
            'audio' => 'm4a', 
            'video' => 'mp4',
            default => 'bin'
        };
        
        $prefix = match ($type) {
            'image' => 'img_',
            'audio' => 'aud_',
            'video' => 'vid_',
            default => 'file_'
        };

        $safeFilename = $this->slugger->slug(uniqid($prefix, true));
        $newFilename = $safeFilename . '.' . $extension;

        try {
            $written = file_put_contents($this->targetDirectory . '/' . $newFilename, $fileData);
            if ($written === false) {
                throw new \Exception("Error saving the $type.");
            }
        } catch (FileException $e) {
             throw new \Exception("Error saving the $type.");
        }

        return '/uploads/' . $folder . '/' . $newFilename;
    }

    public function saveAvatar(?User $user, Request $request, string $destination, string $publicUrl, string $folder, bool $saveInCloud = false): array
    {
        if (!$user) {
            $this->logger->warning("❌ Intento de subir avatar sin estar logueado.");
            return [403, 'Usuario no logueado'];
        }

        /** @var UploadedFile|null $file */
        $file = $request->files->get('file');
        if (!$file) {
            $this->logger->warning("❌ No se ha subido ningún archivo para el avatar.");
            return [400, 'No se ha subido ningún archivo'];
        }

        $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
        if (!in_array(strtolower($file->guessExtension()), $allowedExtensions)) {
            $this->logger->warning("❌ Archivo con extensión no permitida: {$file->getClientOriginalName()}");
            return [400, 'Extensión de archivo no válida. Solo se permiten JPG, PNG y WEBP.'];
        }

        if ($saveInCloud) {
            throw new \RuntimeException('Cloud upload no soportado. Usa Supabase con el flujo de ticket de subida.');
        }

        $originalFilename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeFilename = $this->slugger->slug($originalFilename);
        $newFilename = $safeFilename . '-' . uniqid() . '.' . $file->guessExtension();

        $publicUrl = $publicUrl . $newFilename;

        try {
            $file->move($destination, $newFilename);

            return [200, $publicUrl];
        } catch (FileException $e) {
            $this->logger->error("❌ Error al mover el archivo subido: " . $e->getMessage());
            return [500, 'Error al mover el archivo subido'];
        }
        
    }

}