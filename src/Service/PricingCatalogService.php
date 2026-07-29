<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PricingRate;
use App\Enum\Category;
use App\Repository\PricingRateRepository;

/**
 * Catálogo de precios (fuente de verdad en BD).
 */
final class PricingCatalogService
{
    public const RULES_VERSION = 'pricing-rules-v3';

    public function __construct(
        private readonly PricingRateRepository $pricingRateRepository,
    ) {
    }

    public function labelForCode(string $code): string
    {
        $code = strtoupper(trim($code));
        $category = Category::tryFrom($code);

        return $category?->label() ?? $code;
    }

    public function codeForLabel(string $label): string
    {
        $label = trim($label);
        $fromLabel = Category::tryFromLabel($label);
        if ($fromLabel !== null) {
            return $fromLabel->value;
        }

        $upper = strtoupper($label);
        $fromCode = Category::tryFrom($upper);
        if ($fromCode !== null) {
            return $fromCode->value;
        }

        return Category::DIY->value;
    }

    /**
     * Zonas del catálogo a incluir para una ubicación (orden = prioridad).
     *
     * @return list<string>
     */
    public function resolveZones(?string $location): array
    {
        $loc = mb_strtolower(trim((string) $location));
        if ($loc === '') {
            $loc = 'córdoba, andalucía, españa';
        }

        $highCost = ['madrid', 'barcelona', 'bilbao'];
        foreach ($highCost as $city) {
            if (str_contains($loc, $city)) {
                return ['España'];
            }
        }

        $andalucia = ['córdoba', 'cordoba', 'sevilla', 'málaga', 'malaga', 'granada', 'cádiz', 'cadiz', 'almería', 'almeria', 'jaén', 'jaen', 'huelva', 'andalucía', 'andalucia'];
        $isAndalucia = false;
        foreach ($andalucia as $token) {
            if (str_contains($loc, $token)) {
                $isAndalucia = true;
                break;
            }
        }

        if (str_contains($loc, 'córdoba') || str_contains($loc, 'cordoba')) {
            return ['Córdoba', 'Andalucía', 'España'];
        }

        if ($isAndalucia) {
            return ['Andalucía', 'España'];
        }

        return ['España', 'Andalucía', 'Córdoba'];
    }

    /**
     * @return list<PricingRate>
     */
    public function getRatesForLocation(?string $location): array
    {
        return $this->pricingRateRepository->findByZones($this->resolveZones($location));
    }

    /**
     * CSV en memoria para inyectar en el contexto Gemini (mismo schema histórico).
     */
    public function toCsvForLocation(?string $location): string
    {
        $rates = $this->getRatesForLocation($location);
        $lines = ['Categoria,Subcategoria,Zona,Precio_Min,Precio_Max,Unidad,Complejidad'];
        foreach ($rates as $rate) {
            $lines[] = implode(',', [
                $this->escapeCsv($rate->getCategoryLabel()),
                $this->escapeCsv($rate->getSubcategory()),
                $this->escapeCsv($rate->getZone()),
                (string) $rate->getPriceMin(),
                (string) $rate->getPriceMax(),
                $this->escapeCsv($rate->getUnit()),
                $this->escapeCsv($rate->getComplexity()),
            ]);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Hash estable del slice + reglas (invalida caché Gemini al cambiar tarifas).
     */
    public function contentHashForLocation(?string $location, string $model): string
    {
        $payload = self::RULES_VERSION."\n".$model."\n".$this->toCsvForLocation($location);

        return hash('sha256', $payload);
    }

    private function escapeCsv(string $value): string
    {
        if (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n")) {
            return '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
    }
}
