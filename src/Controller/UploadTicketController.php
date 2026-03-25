<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\SupabaseUploadTicketService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AsController]
#[IsGranted('ROLE_USER')]
class UploadTicketController extends AbstractController
{
    public function __construct(
        private readonly SupabaseUploadTicketService $uploadTicketService,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Obtiene un ticket (signed URL) para subir un avatar.
     * Body: { "contentType": "image/jpeg" }
     * Respuesta: { "signedUrl": "...", "publicUrl": "..." }
     * El cliente hace PUT del archivo a signedUrl; luego envía publicUrl a POST /api/users/avatar.
     */
    #[Route('/api/upload-ticket/avatar', name: 'api_upload_ticket_avatar', methods: ['POST'])]
    public function avatar(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $userId = (int) $user->getId();

        if ($user->getProfessionalProfile() === null && $user->getClientProfile() === null) {
            return new JsonResponse(
                ['error' => 'No tienes perfil de cliente ni profesional'],
                400
            );
        }

        $data = $request->toArray();
        $contentType = $data['contentType'] ?? 'image/jpeg';
        if (!is_string($contentType)) {
            $contentType = 'image/jpeg';
        }

        try {
            $ticket = $this->uploadTicketService->createAvatarUploadTicket($userId, $contentType);
        } catch (\Throwable $e) {
            $this->logger->error('Error generando ticket de avatar: ' . $e->getMessage());
            return new JsonResponse(
                ['error' => 'No se pudo generar el ticket de subida: ' . $e->getMessage()],
                500
            );
        }

        return new JsonResponse([
            'signedUrl' => $ticket['signedUrl'],
            'publicUrl' => $ticket['publicUrl'],
            'expiresIn' => 300,
        ]);
    }

    /**
     * Obtiene un ticket para subir media de una request (photo, audio, video).
     * Body: { "type": "photo" | "audio" | "video" }
     * El cliente hace PUT a signedUrl; luego envía publicUrl en POST /api/requests (photoUrl, audioUrl, videoUrl).
     */
    #[Route('/api/upload-ticket/request-media', name: 'api_upload_ticket_request_media', methods: ['POST'])]
    public function requestMedia(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $clientProfile = $user->getClientProfile();

        if ($clientProfile === null) {
            return new JsonResponse(
                ['error' => 'Solo los clientes pueden subir media de solicitudes'],
                403
            );
        }

        $data = $request->toArray();
        $type = $data['type'] ?? null;
        if (!in_array($type, ['photo', 'audio', 'video'], true)) {
            return new JsonResponse(
                ['error' => 'type debe ser "photo", "audio" o "video"'],
                400
            );
        }

        $userId = (int) $user->getId();

        try {
            $ticket = $this->uploadTicketService->createRequestMediaUploadTicket($userId, $type);
        } catch (\Throwable $e) {
            $this->logger->error('Error generando ticket de request media: ' . $e->getMessage());
            return new JsonResponse(
                ['error' => 'No se pudo generar el ticket de subida: ' . $e->getMessage()],
                500
            );
        }

        return new JsonResponse([
            'signedUrl' => $ticket['signedUrl'],
            'publicUrl' => $ticket['publicUrl'],
            'expiresIn' => 300,
        ]);
    }
}
