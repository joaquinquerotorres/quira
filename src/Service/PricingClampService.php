<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PricingRate;
use App\Repository\PricingRateRepository;

/**
 * Opción C (híbrido A+C): tras diagnose, acota estimated_price_* al rango de pricing_rate.
 * A (caché Gemini) sigue aportando el slice; C garantiza que el API no devuelva precios fuera de catálogo.
 */
final class PricingClampService
{
    /** Urgencias: mismo tope que las reglas cacheadas en Gemini (1.3× Precio_Max). */
    private const IMMEDIATE_MAX_FACTOR = 1.3;

    public function __construct(
        private readonly PricingCatalogService $pricingCatalogService,
        private readonly PricingRateRepository $pricingRateRepository,
    ) {
    }

    /**
     * @param array<string, mixed> $diagnosis
     *
     * @return array<string, mixed>
     */
    public function clampDiagnosis(array $diagnosis, ?string $location): array
    {
        $safeRaw = $diagnosis['safe'] ?? $diagnosis['is_safe'] ?? true;
        $safe = filter_var($safeRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $inScopeRaw = $diagnosis['in_scope'] ?? true;
        $inScope = filter_var($inScopeRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($safe === false || $inScope === false) {
            $diagnosis['estimated_price_min'] = 0;
            $diagnosis['estimated_price_max'] = 0;

            return $diagnosis;
        }

        $pricingType = strtoupper(trim((string) ($diagnosis['pricing_type'] ?? '')));
        if ($pricingType === 'VISIT_REQUIRED') {
            $diagnosis['estimated_price_min'] = 0;
            $diagnosis['estimated_price_max'] = 0;

            return $diagnosis;
        }

        $min = $this->toNonNegativeInt($diagnosis['estimated_price_min'] ?? 0);
        $max = $this->toNonNegativeInt($diagnosis['estimated_price_max'] ?? 0);
        if ($min === 0 && $max === 0) {
            return $diagnosis;
        }

        $rate = $this->findBestRate($diagnosis, $location);
        if ($rate === null) {
            return $diagnosis;
        }

        $catalogMin = $rate->getPriceMin();
        $catalogMax = $rate->getPriceMax();
        $urgency = strtoupper(trim((string) ($diagnosis['urgency'] ?? 'SCHEDULED')));
        $allowedMax = $urgency === 'IMMEDIATE'
            ? (int) round($catalogMax * self::IMMEDIATE_MAX_FACTOR)
            : $catalogMax;

        if ($allowedMax < $catalogMin) {
            $allowedMax = $catalogMin;
        }

        $clampedMin = $this->clampInt($min, $catalogMin, $allowedMax);
        $clampedMax = $this->clampInt($max, $catalogMin, $allowedMax);
        if ($clampedMin > $clampedMax) {
            $mid = (int) round(($clampedMin + $clampedMax) / 2);
            $clampedMin = $mid;
            $clampedMax = $mid;
        }

        $diagnosis['estimated_price_min'] = $clampedMin;
        $diagnosis['estimated_price_max'] = $clampedMax;
        $diagnosis['pricing_clamped'] = ($clampedMin !== $min || $clampedMax !== $max);
        $diagnosis['catalog_price_min'] = $catalogMin;
        $diagnosis['catalog_price_max'] = $catalogMax;
        $diagnosis['catalog_zone'] = $rate->getZone();
        $diagnosis['catalog_subcategory'] = $rate->getSubcategory();

        return $diagnosis;
    }

    /**
     * @param array<string, mixed> $diagnosis
     */
    private function findBestRate(array $diagnosis, ?string $location): ?PricingRate
    {
        $subcategory = trim((string) ($diagnosis['sub_category'] ?? ''));
        if ($subcategory === '') {
            return null;
        }

        $zones = $this->pricingCatalogService->resolveZones($location);
        $categoryCode = strtoupper(trim((string) ($diagnosis['category'] ?? '')));
        $categoryLabel = $this->pricingCatalogService->labelForCode(
            $categoryCode !== '' ? $categoryCode : 'DIY'
        );

        foreach ($zones as $zone) {
            $exact = $this->pricingRateRepository->findOneByCategorySubcategoryZone(
                $categoryLabel,
                $subcategory,
                $zone
            );
            if ($exact !== null) {
                return $exact;
            }
        }

        $rates = $this->pricingCatalogService->getRatesForLocation($location);
        $subLower = mb_strtolower($subcategory);

        foreach ($zones as $zone) {
            foreach ($rates as $rate) {
                if ($rate->getZone() === $zone && $rate->getSubcategory() === $subcategory) {
                    return $rate;
                }
            }
        }

        foreach ($zones as $zone) {
            foreach ($rates as $rate) {
                if ($rate->getZone() === $zone && mb_strtolower($rate->getSubcategory()) === $subLower) {
                    return $rate;
                }
            }
        }

        return null;
    }

    private function clampInt(int $value, int $lo, int $hi): int
    {
        if ($value < $lo) {
            return $lo;
        }
        if ($value > $hi) {
            return $hi;
        }

        return $value;
    }

    private function toNonNegativeInt(mixed $value): int
    {
        if (\is_int($value)) {
            return max(0, $value);
        }
        if (\is_float($value)) {
            return max(0, (int) round($value));
        }
        if (\is_string($value) && is_numeric($value)) {
            return max(0, (int) round((float) $value));
        }

        return 0;
    }
}
