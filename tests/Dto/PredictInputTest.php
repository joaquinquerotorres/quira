<?php

declare(strict_types=1);

namespace App\Tests\Dto;

use App\Dto\PredictInput;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

final class PredictInputTest extends TestCase
{
    public function testValidInputWithDescription(): void
    {
        $input = new PredictInput(description: 'Gotea el grifo');
        $this->assertSame('Gotea el grifo', $input->description);
        $this->assertNull($input->image);
    }

    public function testValidationAcceptsValidInput(): void
    {
        $validator = Validation::createValidator();
        $input = new PredictInput(description: 'Test', location: 'Madrid');
        $violations = $validator->validate($input);
        $this->assertCount(0, $violations);
    }
}
