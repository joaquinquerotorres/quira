<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\RiskLevel;
use PHPUnit\Framework\TestCase;

final class RiskLevelTest extends TestCase
{
    public function testAllCasesExist(): void
    {
        $this->assertCount(3, RiskLevel::cases());
        $this->assertSame('LOW', RiskLevel::LOW->value);
        $this->assertSame('MEDIUM', RiskLevel::MEDIUM->value);
        $this->assertSame('HIGH', RiskLevel::HIGH->value);
    }
}
