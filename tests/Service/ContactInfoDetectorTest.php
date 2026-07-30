<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ContactInfoDetector;
use PHPUnit\Framework\TestCase;

final class ContactInfoDetectorTest extends TestCase
{
    public function testCleanText(): void
    {
        $detector = new ContactInfoDetector();
        self::assertNull($detector->detectReason('Fuga en el lavabo de la cocina'));
        self::assertFalse($detector->containsContactInfo('Fuga en el lavabo de la cocina'));
    }

    public function testDetectsEmailAndPhone(): void
    {
        $detector = new ContactInfoDetector();
        self::assertSame('Se detectó un email en el texto.', $detector->detectReason('Escríbeme a test@example.com'));
        self::assertSame('Se detectó un teléfono en el texto.', $detector->detectReason('Llama al 612345678'));
    }
}
