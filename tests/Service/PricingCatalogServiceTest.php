<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Repository\PricingRateRepository;
use App\Service\PricingCatalogService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class PricingCatalogServiceTest extends TestCase
{
    private function service(): PricingCatalogService
    {
        $repo = $this->createStub(PricingRateRepository::class);
        $em = $this->createStub(EntityManagerInterface::class);

        return new PricingCatalogService($repo, $em, new NullLogger(), \dirname(__DIR__, 2));
    }

    public function testLabelAndCodeMapping(): void
    {
        $svc = $this->service();
        self::assertSame('Fontanería', $svc->labelForCode('PLUMBING'));
        self::assertSame('PLUMBING', $svc->codeForLabel('Fontanería'));
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
