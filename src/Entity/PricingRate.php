<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PricingRateRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Tarifa de catálogo (antes filas de config/gemini_pricing.csv).
 * Precios en céntimos. Zona legible para Gemini: Córdoba | Andalucía | España | …
 */
#[ORM\Entity(repositoryClass: PricingRateRepository::class)]
#[ORM\Table(name: 'pricing_rate')]
#[ORM\UniqueConstraint(name: 'uniq_pricing_rate_cat_sub_zone', columns: ['category_label', 'subcategory', 'zone'])]
#[ORM\Index(name: 'idx_pricing_rate_zone', columns: ['zone'])]
#[ORM\Index(name: 'idx_pricing_rate_category_code', columns: ['category_code'])]
class PricingRate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Código técnico alineado con Category enum (PLUMBING, …). */
    #[ORM\Column(length: 50)]
    private string $categoryCode;

    /** Etiqueta española del CSV / UI (Fontanería, …). */
    #[ORM\Column(length: 100)]
    private string $categoryLabel;

    #[ORM\Column(length: 255)]
    private string $subcategory;

    #[ORM\Column(length: 50)]
    private string $zone;

    #[ORM\Column]
    private int $priceMin;

    #[ORM\Column]
    private int $priceMax;

    #[ORM\Column(length: 50)]
    private string $unit;

    #[ORM\Column(length: 20)]
    private string $complexity;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCategoryCode(): string
    {
        return $this->categoryCode;
    }

    public function setCategoryCode(string $categoryCode): self
    {
        $this->categoryCode = $categoryCode;

        return $this;
    }

    public function getCategoryLabel(): string
    {
        return $this->categoryLabel;
    }

    public function setCategoryLabel(string $categoryLabel): self
    {
        $this->categoryLabel = $categoryLabel;

        return $this;
    }

    public function getSubcategory(): string
    {
        return $this->subcategory;
    }

    public function setSubcategory(string $subcategory): self
    {
        $this->subcategory = $subcategory;

        return $this;
    }

    public function getZone(): string
    {
        return $this->zone;
    }

    public function setZone(string $zone): self
    {
        $this->zone = $zone;

        return $this;
    }

    public function getPriceMin(): int
    {
        return $this->priceMin;
    }

    public function setPriceMin(int $priceMin): self
    {
        $this->priceMin = $priceMin;

        return $this;
    }

    public function getPriceMax(): int
    {
        return $this->priceMax;
    }

    public function setPriceMax(int $priceMax): self
    {
        $this->priceMax = $priceMax;

        return $this;
    }

    public function getUnit(): string
    {
        return $this->unit;
    }

    public function setUnit(string $unit): self
    {
        $this->unit = $unit;

        return $this;
    }

    public function getComplexity(): string
    {
        return $this->complexity;
    }

    public function setComplexity(string $complexity): self
    {
        $this->complexity = $complexity;

        return $this;
    }
}
