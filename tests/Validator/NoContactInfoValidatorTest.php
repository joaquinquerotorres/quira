<?php

declare(strict_types=1);

namespace App\Tests\Validator;

use App\Validator\NoContactInfo;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

final class NoContactInfoValidatorTest extends TestCase
{
    public function testNullIsValid(): void
    {
        $validator = Validation::createValidator();
        $violations = $validator->validate(null, [new NoContactInfo()]);
        $this->assertCount(0, $violations);
    }

    public function testCleanTextIsValid(): void
    {
        $validator = Validation::createValidator();
        $violations = $validator->validate('Solo texto sin contacto', [new NoContactInfo()]);
        $this->assertCount(0, $violations);
    }

    public function testEmailTriggersViolation(): void
    {
        $validator = Validation::createValidator();
        $violations = $validator->validate('Contacta en test@example.com', [new NoContactInfo()]);
        $this->assertCount(1, $violations);
    }

    public function testPhoneTriggersViolation(): void
    {
        $validator = Validation::createValidator();
        $violations = $validator->validate('Llama al 612345678', [new NoContactInfo()]);
        $this->assertCount(1, $violations);
    }
}
