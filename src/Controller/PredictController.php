<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\PredictInput;
use App\Service\GeminiService;
use App\Service\GeminiCacheService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

class PredictController extends AbstractController
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly GeminiService $geminiService,
        private readonly GeminiCacheService $geminiCacheService
    ) {
    }

    #[Route('/api/predict', name: 'api_predict', methods: ['POST'])]
    public function __invoke(
        #[MapRequestPayload] PredictInput $input
    ): JsonResponse {
        $hasContent = !empty($input->description) 
                   || !empty($input->image) 
                   || !empty($input->audio) 
                   || !empty($input->video);

        if (!$hasContent) {
            $this->logger->warning("❌ Solicitud de predicción sin contenido válido. Se requiere al menos una descripción, imagen, audio o video.");
            return new JsonResponse([
                'error' => 'No se ha proporcionado ninguna descripción, imagen, audio o video.'
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $cacheId = $this->geminiCacheService->getActiveCacheId();
            $suggestion = $this->geminiService->diagnose(
                $input->description,
                $input->image,
                $input->audio,
                $input->video,
                $input->location,
                $cacheId
            );

            return $this->json($suggestion);

        } catch (\Exception $e) {
            $this->logger->error("❌ Error al conectar con el servicio de IA: " . $e->getMessage());
            return $this->json([
                'error' => 'Error al conectar con el servicio de IA: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}