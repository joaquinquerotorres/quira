<?php

declare(strict_types=1);

namespace App\Tests\Service\Admin;

use App\Service\Admin\AdminStatsService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class AdminStatsServiceTest extends TestCase
{
    public function testRejectsInvertedRange(): void
    {
        $service = new AdminStatsService($this->createStub(Connection::class));

        $this->expectException(BadRequestHttpException::class);
        $service->overview('2026-07-31', '2026-07-01');
    }

    public function testRejectsRangeOverMaxDays(): void
    {
        $service = new AdminStatsService($this->createStub(Connection::class));

        $this->expectException(BadRequestHttpException::class);
        $service->overview('2025-01-01', '2026-07-01');
    }

    public function testRejectsInvalidDate(): void
    {
        $service = new AdminStatsService($this->createStub(Connection::class));

        $this->expectException(BadRequestHttpException::class);
        $service->overview('31-07-2026', '2026-07-31');
    }

    public function testPreviousPeriodHasSameDayCount(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(0);
        $connection->method('fetchAllAssociative')->willReturn([]);

        $service = new AdminStatsService($connection);
        $payload = $service->overview('2026-07-01', '2026-07-31');

        $this->assertSame('2026-07-01', $payload['period']['from']);
        $this->assertSame('2026-07-31', $payload['period']['to']);
        // 31 días → previousFrom = 2026-05-31, previousTo = 2026-06-30
        $this->assertSame('2026-05-31', $payload['period']['previousFrom']);
        $this->assertSame('2026-06-30', $payload['period']['previousTo']);
        $this->assertSame('day', $payload['timeseries']['grain']);
        $this->assertCount(31, $payload['timeseries']['points']);
        $this->assertSame('2026-07-01', $payload['timeseries']['points'][0]['date']);
        $this->assertSame('2026-07-31', $payload['timeseries']['points'][30]['date']);
        $this->assertSame(0, $payload['timeseries']['points'][0]['newUsers']);
        $this->assertArrayHasKey('cancelAtPeriodEnd', $payload['kpis']);
        $this->assertArrayHasKey('value', $payload['kpis']['cancelAtPeriodEnd']);
        $this->assertArrayNotHasKey('previous', $payload['kpis']['cancelAtPeriodEnd']);
    }
}
