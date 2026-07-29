<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\Category;
use PHPUnit\Framework\TestCase;

final class CategoryTest extends TestCase
{
    public function testAllCasesExist(): void
    {
        self::assertCount(22, Category::cases());
        self::assertContains(Category::DIY, Category::cases());
        self::assertContains(Category::PLUMBING, Category::cases());
        self::assertContains(Category::APPLIANCES, Category::cases());
        self::assertContains(Category::CARE, Category::cases());
        self::assertContains(Category::PEST_CONTROL, Category::cases());
        self::assertContains(Category::SMART_HOME, Category::cases());
    }

    public function testValuesAreUniqueScreamingSnake(): void
    {
        $values = array_map(static fn (Category $c): string => $c->value, Category::cases());
        self::assertSame($values, array_unique($values));
        foreach ($values as $value) {
            self::assertMatchesRegularExpression('/^[A-Z][A-Z0-9_]*$/', $value);
        }
    }

    public function testSpanishLabelsAreUniqueAndMapped(): void
    {
        $labels = array_map(static fn (Category $c): string => $c->label(), Category::cases());
        self::assertCount(22, array_unique($labels));

        self::assertSame('Fontanería', Category::PLUMBING->label());
        self::assertSame('Electrodomésticos', Category::APPLIANCES->label());
        self::assertSame('Mudanzas y Portes', Category::MOVING->label());
        self::assertSame('Cerrajería', Category::LOCKSMITH->label());
        self::assertSame('Mantenimiento de Piscinas', Category::POOL->label());
        self::assertSame('Domótica y Seguridad', Category::SMART_HOME->label());
        self::assertSame('Cuidados', Category::CARE->label());
    }

    public function testTryFromLabelRoundTrip(): void
    {
        foreach (Category::cases() as $case) {
            self::assertSame($case, Category::tryFromLabel($case->label()));
            self::assertSame($case, Category::tryFrom($case->value));
        }
        self::assertNull(Category::tryFromLabel('Desconocido'));
    }
}
