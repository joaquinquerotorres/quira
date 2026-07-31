<?php

declare(strict_types=1);

namespace App\Tests\Validator;

use App\Validator\ExecutionTimeOption;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

final class ExecutionTimeOptionValidatorTest extends TestCase
{
    public function testNullAndEmptyAreValid(): void
    {
        $constraint = new ExecutionTimeOption(presets: ['Hoy mismo']);
        $validator = Validation::createValidator();

        $this->assertCount(0, $validator->validate(null, [$constraint]));
        $this->assertCount(0, $validator->validate('', [$constraint]));
    }

    public function testPresetIsValid(): void
    {
        $constraint = new ExecutionTimeOption(presets: ['Esta semana', 'Mañana']);
        $validator = Validation::createValidator();

        $this->assertCount(0, $validator->validate('Esta semana', [$constraint]));
    }

    public function testFechaConcretaIsValid(): void
    {
        $constraint = new ExecutionTimeOption(presets: ['Hoy mismo']);
        $validator = Validation::createValidator();

        $this->assertCount(0, $validator->validate('Fecha concreta: 15/08/2026', [$constraint]));
    }

    public function testInvalidValueFails(): void
    {
        $constraint = new ExecutionTimeOption(
            presets: ['Hoy mismo'],
            message: 'La fecha estimada de realización no es válida.',
        );
        $validator = Validation::createValidator();
        $violations = $validator->validate('INVALID', [$constraint]);

        $this->assertCount(1, $violations);
        $this->assertSame('La fecha estimada de realización no es válida.', $violations[0]->getMessage());
    }

    public function testMalformedFechaConcretaFails(): void
    {
        $constraint = new ExecutionTimeOption(presets: []);
        $validator = Validation::createValidator();

        $this->assertCount(1, $validator->validate('Fecha concreta: 15-08-2026', [$constraint]));
        $this->assertCount(1, $validator->validate('Fecha concreta: 2026/08/15', [$constraint]));
        $this->assertCount(1, $validator->validate('Fecha concreta:15/08/2026', [$constraint]));
    }
}
