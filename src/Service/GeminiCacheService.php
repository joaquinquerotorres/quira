<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\GeminiCache;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Gestiona el ID de cachedContents de Gemini.
 * Degrada a null si Google falla (diagnose puede seguir sin cachedContent).
 */
final class GeminiCacheService
{
    private const LOCK_NAME = 'quira_gemini_cache_create';
    private const LOCK_TIMEOUT_SEC = 15;
    private const TTL_SECONDS = 3600;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GeminiService $geminiService,
        private readonly PricingCatalogService $pricingCatalogService,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(default:env_default_gemini_model:GEMINI_MODEL)%')]
        private readonly string $model,
    ) {
    }

    /**
     * @return string|null Resource name de Google, o null si no hay caché usable
     */
    public function getActiveCacheId(?string $location = null): ?string
    {
        $model = $this->normalizeModelName($this->model);
        $zones = $this->pricingCatalogService->resolveZones($location);
        $zoneKey = implode('|', $zones);
        $contentHash = $this->pricingCatalogService->contentHashForLocation($location, $model);

        $existing = $this->findUsable($model, $contentHash, $zoneKey);
        if ($existing !== null) {
            return $existing->getCacheId();
        }

        $conn = $this->em->getConnection();
        if (!$this->acquireLock($conn)) {
            $this->logger->warning('No se pudo adquirir lock para crear caché Gemini; reintentando lectura');
            $existing = $this->findUsable($model, $contentHash, $zoneKey);

            return $existing?->getCacheId();
        }

        try {
            // Otro proceso pudo crear la caché mientras esperábamos el lock.
            $existing = $this->findUsable($model, $contentHash, $zoneKey);
            if ($existing !== null) {
                return $existing->getCacheId();
            }

            $catalogCsv = $this->pricingCatalogService->toCsvForLocation($location);
            $cacheId = $this->geminiService->createCache($catalogCsv, $model);
            if ($cacheId === null || $cacheId === '') {
                $this->logger->error('Gemini createCache no devolvió ID; diagnose seguirá sin cachedContent');

                return null;
            }

            $row = new GeminiCache();
            $row->setCacheId($cacheId);
            $row->setModel($model);
            $row->setContentHash($contentHash);
            $row->setZoneKey($zoneKey);
            $row->setExpiresAt(new \DateTimeImmutable('+' . self::TTL_SECONDS . ' seconds'));
            $this->em->persist($row);
            $this->em->flush();

            return $cacheId;
        } finally {
            $this->releaseLock($conn);
        }
    }

    /**
     * Invalida cachés locales (tras calibrar tarifas). Google expirará por TTL.
     */
    public function invalidateAll(): int
    {
        $now = new \DateTimeImmutable();
        $qb = $this->em->createQueryBuilder()
            ->update(GeminiCache::class, 'c')
            ->set('c.expiresAt', ':past')
            ->where('c.expiresAt > :now')
            ->setParameter('past', $now->modify('-1 minute'))
            ->setParameter('now', $now);

        return (int) $qb->getQuery()->execute();
    }

    private function findUsable(string $model, string $contentHash, string $zoneKey): ?GeminiCache
    {
        $minExpiry = new \DateTimeImmutable('+5 minutes');

        /** @var GeminiCache|null $cache */
        $cache = $this->em->getRepository(GeminiCache::class)
            ->createQueryBuilder('c')
            ->andWhere('c.model = :model')
            ->andWhere('c.contentHash = :hash')
            ->andWhere('c.zoneKey = :zoneKey')
            ->andWhere('c.expiresAt > :minExpiry')
            ->setParameter('model', $model)
            ->setParameter('hash', $contentHash)
            ->setParameter('zoneKey', $zoneKey)
            ->setParameter('minExpiry', $minExpiry)
            ->orderBy('c.expiresAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $cache;
    }

    private function acquireLock(Connection $conn): bool
    {
        try {
            $result = $conn->fetchOne('SELECT GET_LOCK(?, ?)', [self::LOCK_NAME, self::LOCK_TIMEOUT_SEC]);

            return (int) $result === 1;
        } catch (\Throwable $e) {
            $this->logger->warning('GET_LOCK falló: ' . $e->getMessage());

            return false;
        }
    }

    private function releaseLock(Connection $conn): void
    {
        try {
            $conn->fetchOne('SELECT RELEASE_LOCK(?)', [self::LOCK_NAME]);
        } catch (\Throwable) {
        }
    }

    private function normalizeModelName(string $model): string
    {
        $model = trim($model);
        if (str_starts_with($model, 'models/')) {
            return substr($model, strlen('models/'));
        }

        return $model;
    }
}
