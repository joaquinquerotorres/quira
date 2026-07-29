<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Repository\PricingRateRepository;
use App\Service\PricingCatalogService;
use PHPUnit\Framework\TestCase;

final class PricingCatalogServiceTest extends TestCase
{
    private function service(): PricingCatalogService
    {
        $repo = $this->createStub(PricingRateRepository::class);

        return new PricingCatalogService($repo);
    }

    public function testLabelAndCodeMapping(): void
    {
        $svc = $this->service();
        self::assertSame('Fontanería', $svc->labelForCode('PLUMBING'));
        self::assertSame('PLUMBING', $svc->codeForLabel('Fontanería'));
        self::assertSame('APPLIANCES', $svc->codeForLabel('Electrodomésticos'));
        self::assertSame('Mantenimiento de Piscinas', $svc->labelForCode('POOL'));
        self::assertSame('PEST_CONTROL', $svc->codeForLabel('Control de Plagas'));
        self::assertSame('DIY', $svc->codeForLabel('Desconocido'));
    }

    public function testResolveZonesCordobaIncludesLocalAndRegional(): void
    {
        $svc = $this->service();
        self::assertSame(
            ['Córdoba', 'Andalucía', 'España'],
            $svc->resolveZones('Córdoba, Andalucía, España')
        );
        self::assertSame(
            ['Córdoba', 'Andalucía', 'España'],
            $svc->resolveZones(null)
        );
    }

    public function testResolveZonesSevillaSkipsCordobaSpecific(): void
    {
        $svc = $this->service();
        self::assertSame(['Andalucía', 'España'], $svc->resolveZones('Sevilla'));
    }

    public function testResolveZonesMadridUsesNational(): void
    {
        $svc = $this->service();
        self::assertSame(['España'], $svc->resolveZones('Madrid Centro'));
    }
}
