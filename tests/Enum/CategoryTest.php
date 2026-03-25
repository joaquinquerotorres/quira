<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\Category;
use PHPUnit\Framework\TestCase;

final class CategoryTest extends TestCase
{
    public function testAllCasesExist(): void
    {
        $this->assertGreaterThanOrEqual(7, count(Category::cases()));
        $this->assertContains(Category::DIY, Category::cases());
        $this->assertContains(Category::PLUMBING, Category::cases());
    }
}
