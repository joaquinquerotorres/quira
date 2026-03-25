<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\MediaService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AsController]
#[IsGranted('ROLE_USER')]
class AvatarUploadController extends AbstractController
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly EntityManagerInterface $entityManager,
        private readonly MediaService $mediaService
    ) {
    }

    #[Route('/api/users/avatar', name: 'api_users_avatar_upload', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        $publicUrl = null;

        if ($request->headers->get('Content-Type') === 'application/json') {
            $data = json_decode($request->getContent(), true);
            $url = $data['url'] ?? null;
            if (is_string($url) && str_starts_with($url, 'https://')) {
                $publicUrl = $url;
            }
        }

        if ($publicUrl === null) {
            $destination = $this->getParameter('kernel.project_dir') . '/public/uploads/avatars';
            $basePublicUrl = '/uploads/avatars/';
            [$httpCode, $result] = $this->mediaService->saveAvatar($user, $request, $destination, $basePublicUrl, 'avatar');
            if ($httpCode !== 200) {
                return new JsonResponse(['error' => $result], $httpCode);
            }
            $publicUrl = $result;
        }

        $profileUpdated = false;
        if ($user->getProfessionalProfile()) {
            $user->getProfessionalProfile()->setAvatar($publicUrl);
            $profileUpdated = true;
        } 
        elseif ($user->getClientProfile()) {
            $user->getClientProfile()->setAvatar($publicUrl);
            $profileUpdated = true;
        }

        if ($profileUpdated) {
            $this->entityManager->flush();

            $this->logger->info("✅ Avatar actualizado correctamente para el usuario {$user->getUserIdentifier()}.");
            return new JsonResponse([
                'url' => $publicUrl,
                'message' => 'Avatar actualizado correctamente'
            ], 200);
        }

        $this->logger->error("❌ El usuario {$user->getUserIdentifier()} no tiene un perfil asociado para actualizar el avatar.");
        return new JsonResponse(['error' => 'El usuario no tiene un perfil asociado (Cliente o Profesional)'], 400);
    }
}