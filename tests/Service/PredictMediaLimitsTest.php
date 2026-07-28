<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\PredictMediaLimits;
use PHPUnit\Framework\TestCase;

final class PredictMediaLimitsTest extends TestCase
{
    public function testMaxBytesForRequestMediaTypes(): void
    {
        $this->assertSame(10_000_000, PredictMediaLimits::maxBytesFor('photo'));
        $this->assertSame(10_000_000, PredictMediaLimits::maxBytesFor('image'));
        $this->assertSame(12_000_000, PredictMediaLimits::maxBytesFor('audio'));
        $this->assertSame(40_000_000, PredictMediaLimits::maxBytesFor('video'));
    }

    public function testAllExposesUniformClientCaps(): void
    {
        $this->assertSame([
            'image' => 10_000_000,
            'audio' => 12_000_000,
            'video' => 40_000_000,
        ], PredictMediaLimits::all());
    }
}
