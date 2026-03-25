<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\BidStatus;
use PHPUnit\Framework\TestCase;

final class BidStatusTest extends TestCase
{
    public function testAllCasesExist(): void
    {
        $this->assertCount(4, BidStatus::cases());
        $this->assertContains(BidStatus::PENDING, BidStatus::cases());
        $this->assertContains(BidStatus::ACCEPTED, BidStatus::cases());
        $this->assertContains(BidStatus::REJECTED, BidStatus::cases());
        $this->assertContains(BidStatus::COMPLETED, BidStatus::cases());
        $this->assertNotContains('CANCELLED', array_map(fn($c) => $c->value, BidStatus::cases()));
    }
}
