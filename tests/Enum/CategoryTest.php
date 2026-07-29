<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\Category;
use PHPUnit\Framework\TestCase;

final class CategoryTest extends TestCase
{
    public function testAllCasesExist(): void
    {
        $this->assertCount(22, Category::cases());
        $this->assertContains(Category::DIY, Category::cases());
        $this->assertContains(Category::PLUMBING, Category::cases());
        $this->assertContains(Category::APPLIANCES, Category::cases());
        $this->assertContains(Category::CARE, Category::cases());
        $this->assertContains(Category::PEST_CONTROL, Category::cases());
    }
}
