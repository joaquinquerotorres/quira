<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\PredictInput;
use App\Entity\PredictTask;
use App\Entity\User;
use App\Message\AnalyzePredictMessage;
use App\Repository\PredictTaskRepository;
use App\Service\GeminiCacheService;
use App\Service\GeminiService;
use App\Service\PredictMediaFetcher;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

class PredictController extends AbstractController
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly GeminiService $geminiService,
        private readonly GeminiCacheService $geminiCacheService,
        private readonly PredictMediaFetcher $mediaFetcher,
        private readonly EntityManagerInterface $entityManager,
        private readonly PredictTaskRepository $predictTaskRepository,
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    #[Route('/api/predict', name: 'api_predict', methods: ['POST'])]
    public function __invoke(
        #[MapRequestPayload] PredictInput $input
    ): JsonResponse {
        if (!$input->hasContent()) {
            $this->logger->warning('Solicitud de predicción sin contenido válido.');
            return new JsonResponse([
                'error' => 'No se ha proporcionado ninguna descripción, imagen, audio o video.',
            ], Response::HTTP_BAD_REQUEST);
        }

        /** @var User $user */
        $user = $this->getUser();

        // Camino preferido: URLs (o solo texto) → tarea + Messenger
        if ($input->hasUrlMedia() || (!$input->hasLegacyBase64Media() && $input->description)) {
            return $this->enqueuePredictTask($user, $input);
        }

        // Legacy: base64 embebido (síncrono). Mantener por compatibilidad.
        return $this->legacySyncPredict($input);
    }

    #[Route('/api/predict/tasks/{publicId}', name: 'api_predict_task_status', methods: ['GET'])]
    public function taskStatus(string $publicId): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $task = $this->predictTaskRepository->findOneByPublicId($publicId);
        if ($task === null || $task->getUser()->getId() !== $user->getId()) {
            return new JsonResponse(['error' => 'Tarea no encontrada.'], Response::HTTP_NOT_FOUND);
        }

        $payload = [
            'taskId' => $task->getPublicId(),
            'status' => $task->getStatus(),
        ];

        if ($task->getStatus() === PredictTask::STATUS_COMPLETED && is_array($task->getResult())) {
            $payload['result'] = $task->getResult();
        }
        if ($task->getStatus() === PredictTask::STATUS_FAILED) {
            $payload['error'] = $task->getErrorMessage() ?? 'Error al procesar el análisis.';
        }

        return $this->json($payload);
    }

    private function enqueuePredictTask(User $user, PredictInput $input): JsonResponse
    {
        try {
            foreach (['image' => $input->imageUrl, 'audio' => $input->audioUrl, 'video' => $input->videoUrl] as $kind => $url) {
                if (is_string($url) && trim($url) !== '') {
                    $this->mediaFetcher->assertAllowedPublicUrl($url);
                }
            }
        } catch (\RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $task = new PredictTask($user, Uuid::v4()->toRfc4122());
        $task->setDescription($input->description);
        $task->setImageUrl($this->nullIfEmpty($input->imageUrl));
        $task->setAudioUrl($this->nullIfEmpty($input->audioUrl));
        $task->setVideoUrl($this->nullIfEmpty($input->videoUrl));
        $task->setLocation($input->location);

        $this->entityManager->persist($task);
        $this->entityManager->flush();

        $this->messageBus->dispatch(new AnalyzePredictMessage((int) $task->getId()));
        $this->entityManager->refresh($task);

        if ($task->getStatus() === PredictTask::STATUS_COMPLETED && is_array($task->getResult())) {
            return $this->json([
                'taskId' => $task->getPublicId(),
                'status' => PredictTask::STATUS_COMPLETED,
                'result' => $task->getResult(),
            ]);
        }

        if ($task->getStatus() === PredictTask::STATUS_FAILED) {
            return new JsonResponse([
                'taskId' => $task->getPublicId(),
                'status' => PredictTask::STATUS_FAILED,
                'error' => $task->getErrorMessage() ?? 'Error al conectar con el servicio de IA.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json([
            'taskId' => $task->getPublicId(),
            'status' => $task->getStatus(),
        ], Response::HTTP_ACCEPTED);
    }

    private function legacySyncPredict(PredictInput $input): JsonResponse
    {
        try {
            set_time_limit(300);
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
            $this->logger->error('Error al conectar con el servicio de IA: ' . $e->getMessage());
            return $this->json([
                'error' => 'Error al conectar con el servicio de IA: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function nullIfEmpty(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
