<?php

declare(strict_types=1);

namespace App\Tests\Serializer;

use App\Serializer\PointDenormalizer;
use LongitudeOne\Spatial\PHP\Types\Geometry\Point;
use PHPUnit\Framework\TestCase;

final class PointDenormalizerTest extends TestCase
{
    private PointDenormalizer $denormalizer;

    protected function setUp(): void
    {
        $this->denormalizer = new PointDenormalizer();
    }

    public function testDenormalizeFromCoordinates(): void
    {
        $data = ['type' => 'Point', 'coordinates' => [-4.77, 37.88]];
        $point = $this->denormalizer->denormalize($data, Point::class);
        $this->assertInstanceOf(Point::class, $point);
        $this->assertSame(-4.77, $point->getLongitude());
        $this->assertSame(37.88, $point->getLatitude());
    }

    public function testDenormalizeFromXy(): void
    {
        $data = ['x' => -4.77, 'y' => 37.88];
        $point = $this->denormalizer->denormalize($data, Point::class);
        $this->assertInstanceOf(Point::class, $point);
        $this->assertSame(-4.77, $point->getLongitude());
        $this->assertSame(37.88, $point->getLatitude());
    }

    public function testSupportsPointClass(): void
    {
        $this->assertTrue($this->denormalizer->supportsDenormalization(['coordinates' => [0, 0]], Point::class));
        $this->assertFalse($this->denormalizer->supportsDenormalization([], \stdClass::class));
    }

    public function testThrowsOnInvalidFormat(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('formato del punto');
        $this->denormalizer->denormalize(['invalid' => 'data'], Point::class);
    }
}
