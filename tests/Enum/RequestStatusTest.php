<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\RequestStatus;
use PHPUnit\Framework\TestCase;

final class RequestStatusTest extends TestCase
{
    public function testAllCasesExist(): void
    {
        $cases = RequestStatus::cases();
        $this->assertContains(RequestStatus::PENDING, $cases);
        $this->assertContains(RequestStatus::ACCEPTED, $cases);
        $this->assertContains(RequestStatus::COMPLETED, $cases);
        $this->assertContains(RequestStatus::CANCELLED, $cases);
        $this->assertContains(RequestStatus::PENDING_APPROVAL, $cases);
        $this->assertCount(5, $cases);
    }

    public function testValues(): void
    {
        $this->assertSame('PENDING', RequestStatus::PENDING->value);
        $this->assertSame('ACCEPTED', RequestStatus::ACCEPTED->value);
        $this->assertSame('COMPLETED', RequestStatus::COMPLETED->value);
    }
}
