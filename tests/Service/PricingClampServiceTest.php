<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\PricingRate;
use App\Repository\PricingRateRepository;
use App\Service\PricingCatalogService;
use App\Service\PricingClampService;
use PHPUnit\Framework\TestCase;

final class PricingClampServiceTest extends TestCase
{
    private function rate(
        string $label,
        string $sub,
        string $zone,
        int $min,
        int $max,
        string $code = 'PLUMBING',
    ): PricingRate {
        $rate = new PricingRate();
        $rate->setCategoryCode($code);
        $rate->setCategoryLabel($label);
        $rate->setSubcategory($sub);
        $rate->setZone($zone);
        $rate->setPriceMin($min);
        $rate->setPriceMax($max);
        $rate->setUnit('Unidad');
        $rate->setComplexity('Media');

        return $rate;
    }

    /**
     * @param list<PricingRate> $rates
     */
    private function service(array $rates): PricingClampService
    {
        $repo = $this->createStub(PricingRateRepository::class);
        $repo->method('findByZones')->willReturnCallback(
            static function (array $zones) use ($rates): array {
                return array_values(array_filter(
                    $rates,
                    static fn (PricingRate $r): bool => \in_array($r->getZone(), $zones, true)
                ));
            }
        );
        $repo->method('findOneByCategorySubcategoryZone')->willReturnCallback(
            static function (string $label, string $sub, string $zone) use ($rates): ?PricingRate {
                foreach ($rates as $rate) {
                    if (
                        $rate->getCategoryLabel() === $label
                        && $rate->getSubcategory() === $sub
                        && $rate->getZone() === $zone
                    ) {
                        return $rate;
                    }
                }

                return null;
            }
        );

        return new PricingClampService(new PricingCatalogService($repo), $repo);
    }

    public function testVisitRequiredForcesZero(): void
    {
        $svc = $this->service([
            $this->rate('Fontanería', 'Desatasco manual de sifón/desagüe', 'Córdoba', 4000, 8000),
        ]);

        $out = $svc->clampDiagnosis([
            'pricing_type' => 'VISIT_REQUIRED',
            'category' => 'PLUMBING',
            'sub_category' => 'Desatasco manual de sifón/desagüe',
            'estimated_price_min' => 5000,
            'estimated_price_max' => 9000,
            'urgency' => 'SCHEDULED',
        ], 'Córdoba');

        self::assertSame(0, $out['estimated_price_min']);
        self::assertSame(0, $out['estimated_price_max']);
        self::assertArrayNotHasKey('pricing_clamped', $out);
    }

    public function testSkipsClampWhenUnsafeOrOutOfScope(): void
    {
        $svc = $this->service([
            $this->rate('Fontanería', 'Desatasco manual de sifón/desagüe', 'Córdoba', 4000, 8000),
        ]);

        $unsafe = $svc->clampDiagnosis([
            'safe' => false,
            'in_scope' => true,
            'pricing_type' => 'RANGE',
            'category' => 'PLUMBING',
            'sub_category' => 'Desatasco manual de sifón/desagüe',
            'estimated_price_min' => 1000,
            'estimated_price_max' => 20000,
            'urgency' => 'SCHEDULED',
        ], 'Córdoba');
        self::assertSame(0, $unsafe['estimated_price_min']);
        self::assertSame(0, $unsafe['estimated_price_max']);

        $oos = $svc->clampDiagnosis([
            'safe' => true,
            'in_scope' => false,
            'pricing_type' => 'RANGE',
            'category' => 'PLUMBING',
            'sub_category' => 'Desatasco manual de sifón/desagüe',
            'estimated_price_min' => 1000,
            'estimated_price_max' => 20000,
            'urgency' => 'SCHEDULED',
        ], 'Córdoba');
        self::assertSame(0, $oos['estimated_price_min']);
        self::assertSame(0, $oos['estimated_price_max']);
    }

    public function testClampsOutOfRangeToCatalog(): void
    {
        $svc = $this->service([
            $this->rate('Fontanería', 'Desatasco manual de sifón/desagüe', 'Córdoba', 4000, 8000),
        ]);

        $out = $svc->clampDiagnosis([
            'pricing_type' => 'RANGE',
            'category' => 'PLUMBING',
            'sub_category' => 'Desatasco manual de sifón/desagüe',
            'estimated_price_min' => 1000,
            'estimated_price_max' => 20000,
            'urgency' => 'SCHEDULED',
        ], 'Córdoba, Andalucía, España');

        self::assertSame(4000, $out['estimated_price_min']);
        self::assertSame(8000, $out['estimated_price_max']);
        self::assertTrue($out['pricing_clamped']);
        self::assertSame(4000, $out['catalog_price_min']);
        self::assertSame(8000, $out['catalog_price_max']);
        self::assertSame('Córdoba', $out['catalog_zone']);
    }

    public function testImmediateAllowsThirtyPercentAboveMax(): void
    {
        $svc = $this->service([
            $this->rate('Fontanería', 'Desatasco manual de sifón/desagüe', 'Córdoba', 4000, 8000),
        ]);

        $out = $svc->clampDiagnosis([
            'pricing_type' => 'RANGE',
            'category' => 'PLUMBING',
            'sub_category' => 'Desatasco manual de sifón/desagüe',
            'estimated_price_min' => 7000,
            'estimated_price_max' => 15000,
            'urgency' => 'IMMEDIATE',
        ], 'Córdoba');

        self::assertSame(7000, $out['estimated_price_min']);
        self::assertSame(10400, $out['estimated_price_max']); // 8000 * 1.3
        self::assertTrue($out['pricing_clamped']);
    }

    public function testLeavesEstimateWhenNoCatalogMatch(): void
    {
        $svc = $this->service([
            $this->rate('Fontanería', 'Otro servicio', 'Córdoba', 4000, 8000),
        ]);

        $out = $svc->clampDiagnosis([
            'pricing_type' => 'RANGE',
            'category' => 'PLUMBING',
            'sub_category' => 'Servicio inventado por Gemini',
            'estimated_price_min' => 12000,
            'estimated_price_max' => 18000,
            'urgency' => 'SCHEDULED',
        ], 'Córdoba');

        self::assertSame(12000, $out['estimated_price_min']);
        self::assertSame(18000, $out['estimated_price_max']);
        self::assertArrayNotHasKey('pricing_clamped', $out);
    }

    public function testMatchesSubcategoryIgnoringWrongCategoryLabel(): void
    {
        $svc = $this->service([
            $this->rate('Fontanería', 'Desatasco manual de sifón/desagüe', 'Córdoba', 4000, 8000),
        ]);

        $out = $svc->clampDiagnosis([
            'pricing_type' => 'FIXED',
            'category' => 'DIY',
            'sub_category' => 'Desatasco manual de sifón/desagüe',
            'estimated_price_min' => 500,
            'estimated_price_max' => 500,
            'urgency' => 'SCHEDULED',
        ], 'Córdoba');

        self::assertSame(4000, $out['estimated_price_min']);
        self::assertSame(4000, $out['estimated_price_max']);
        self::assertTrue($out['pricing_clamped']);
    }

    public function testPrefersCordobaZoneOverAndalucia(): void
    {
        $svc = $this->service([
            $this->rate('Fontanería', 'Desatasco manual de sifón/desagüe', 'Andalucía', 9000, 18000),
            $this->rate('Fontanería', 'Desatasco manual de sifón/desagüe', 'Córdoba', 4000, 8000),
        ]);

        $out = $svc->clampDiagnosis([
            'pricing_type' => 'RANGE',
            'category' => 'PLUMBING',
            'sub_category' => 'Desatasco manual de sifón/desagüe',
            'estimated_price_min' => 1000,
            'estimated_price_max' => 50000,
            'urgency' => 'SCHEDULED',
        ], 'Córdoba');

        self::assertSame('Córdoba', $out['catalog_zone']);
        self::assertSame(4000, $out['estimated_price_min']);
        self::assertSame(8000, $out['estimated_price_max']);
    }

    public function testCaseInsensitiveSubcategoryMatch(): void
    {
        $svc = $this->service([
            $this->rate('Fontanería', 'Desatasco manual de sifón/desagüe', 'Córdoba', 4000, 8000),
        ]);

        $out = $svc->clampDiagnosis([
            'pricing_type' => 'RANGE',
            'category' => 'PLUMBING',
            'sub_category' => 'DESATASCO MANUAL DE SIFÓN/DESAGÜE',
            'estimated_price_min' => 1000,
            'estimated_price_max' => 20000,
            'urgency' => 'SCHEDULED',
        ], 'Córdoba');

        self::assertSame(4000, $out['estimated_price_min']);
        self::assertSame(8000, $out['estimated_price_max']);
        self::assertTrue($out['pricing_clamped']);
    }

    public function testZeroPricesSkipped(): void
    {
        $svc = $this->service([
            $this->rate('Fontanería', 'Desatasco manual de sifón/desagüe', 'Córdoba', 4000, 8000),
        ]);

        $out = $svc->clampDiagnosis([
            'pricing_type' => 'RANGE',
            'category' => 'PLUMBING',
            'sub_category' => 'Desatasco manual de sifón/desagüe',
            'estimated_price_min' => 0,
            'estimated_price_max' => 0,
            'urgency' => 'SCHEDULED',
        ], 'Córdoba');

        self::assertSame(0, $out['estimated_price_min']);
        self::assertSame(0, $out['estimated_price_max']);
        self::assertArrayNotHasKey('pricing_clamped', $out);
    }
}
