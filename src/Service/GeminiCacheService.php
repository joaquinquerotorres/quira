<?php

namespace App\Service;

use App\Entity\GeminiCache;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class GeminiCacheService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GeminiService $geminiService,
        private readonly LoggerInterface $logger
    ) {}

    public function getActiveCacheId(): string
    {
        $cache = $this->em->getRepository(GeminiCache::class)
        ->createQueryBuilder('c')
        ->where('c.expiresAt > :now')
        ->setParameter('now', new \DateTimeImmutable())
        ->setMaxResults(1)
        ->getQuery()
        ->getOneOrNullResult();

        if ($cache) {
            return $cache->cacheId;
        }

        $cacheId = $this->geminiService->createCache();

        if (empty($cacheId)) {
            $this->logger->error("❌ Error al crear la caché: No se recibió un ID válido de Google");
            throw new \Exception("Error al crear la caché porque no se recibió un ID válido de Google.");
        }
        
        $newCache = new GeminiCache();
        $newCache->cacheId = $cacheId;
        $newCache->expiresAt = new \DateTimeImmutable('+1 hour');
        
        $this->em->persist($newCache);
        $this->em->flush();

        return $newCache->cacheId;
    }
}