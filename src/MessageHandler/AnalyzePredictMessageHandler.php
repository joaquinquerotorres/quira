<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\AnalyzePredictMessage;
use App\Repository\PredictTaskRepository;
use App\Service\GeminiCacheService;
use App\Service\GeminiService;
use App\Service\PredictMediaFetcher;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class AnalyzePredictMessageHandler
{
    public function __construct(
        private readonly PredictTaskRepository $predictTaskRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly PredictMediaFetcher $mediaFetcher,
        private readonly GeminiService $geminiService,
        private readonly GeminiCacheService $geminiCacheService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(AnalyzePredictMessage $message): void
    {
        $task = $this->predictTaskRepository->find($message->predictTaskId);
        if ($task === null) {
            $this->logger->warning('AnalyzePredictMessage: task not found', [
                'predictTaskId' => $message->predictTaskId,
            ]);
            return;
        }

        if ($task->getStatus() === $task::STATUS_COMPLETED) {
            return;
        }

        $task->markProcessing();
        $this->entityManager->flush();

        try {
            set_time_limit(300);

            $image = null;
            $audio = null;
            $video = null;

            if ($task->getImageUrl()) {
                $image = $this->mediaFetcher->fetchAsDataUrl($task->getImageUrl(), 'image');
            }
            if ($task->getAudioUrl()) {
                $audio = $this->mediaFetcher->fetchAsDataUrl($task->getAudioUrl(), 'audio');
            }
            if ($task->getVideoUrl()) {
                $video = $this->mediaFetcher->fetchAsDataUrl($task->getVideoUrl(), 'video');
            }

            $cacheId = $this->geminiCacheService->getActiveCacheId($task->getLocation());
            $suggestion = $this->geminiService->diagnose(
                $task->getDescription(),
                $image,
                $audio,
                $video,
                $task->getLocation(),
                $cacheId
            );

            $task->markCompleted($suggestion);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->logger->error('AnalyzePredictMessage failed', [
                'predictTaskId' => $message->predictTaskId,
                'publicId' => $task->getPublicId(),
                'error' => $e->getMessage(),
            ]);
            $task->markFailed('Error al conectar con el servicio de IA: ' . $e->getMessage());
            $this->entityManager->flush();
        }
    }
}
