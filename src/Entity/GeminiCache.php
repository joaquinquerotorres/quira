<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Registro local del cachedContent remoto de Gemini (no es la tabla de precios).
 */
#[ORM\Entity]
#[ORM\Table(name: 'gemini_cache')]
#[ORM\Index(name: 'idx_gemini_cache_lookup', columns: ['model', 'content_hash', 'expires_at'])]
class GeminiCache
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Resource name de Google, p.ej. cachedContents/… */
    #[ORM\Column(length: 255)]
    private string $cacheId;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(length: 80)]
    private string $model;

    /** SHA-256 del slice de catálogo + reglas + modelo. */
    #[ORM\Column(length: 64)]
    private string $contentHash;

    /** Clave de zona del slice (p.ej. Córdoba|Andalucía|España). */
    #[ORM\Column(length: 120)]
    private string $zoneKey;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCacheId(): string
    {
        return $this->cacheId;
    }

    public function setCacheId(string $cacheId): self
    {
        $this->cacheId = $cacheId;

        return $this;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(\DateTimeImmutable $expiresAt): self
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function setModel(string $model): self
    {
        $this->model = $model;

        return $this;
    }

    public function getContentHash(): string
    {
        return $this->contentHash;
    }

    public function setContentHash(string $contentHash): self
    {
        $this->contentHash = $contentHash;

        return $this;
    }

    public function getZoneKey(): string
    {
        return $this->zoneKey;
    }

    public function setZoneKey(string $zoneKey): self
    {
        $this->zoneKey = $zoneKey;

        return $this;
    }

    /**
     * Margen de seguridad: no reutilizar un caché a punto de caducar en Google.
     */
    public function isUsable(\DateTimeImmutable $now = new \DateTimeImmutable()): bool
    {
        return $this->expiresAt > $now->modify('+5 minutes');
    }
}
