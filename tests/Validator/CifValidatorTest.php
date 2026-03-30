<?php

declare(strict_types=1);

namespace App\Tests\Validator;

use App\Validator\CifValidator;
use PHPUnit\Framework\TestCase;

final class CifValidatorTest extends TestCase
{
    public function testValidCifFromExample(): void
    {
        // Ejemplo de CIF válido en fuentes públicas: A58818501
        $this->assertTrue(CifValidator::isValidCif('A58818501'));
    }

    public function testInvalidCifControlChar(): void
    {
        $this->assertFalse(CifValidator::isValidCif('A58818500'));
    }
}

