<?php

declare(strict_types=1);

namespace App\Tests\Validator;

use App\Validator\CleanText;
use App\Validator\CleanTextValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Context\ExecutionContextFactory;
use Symfony\Component\Validator\Validation;

final class CleanTextValidatorTest extends TestCase
{
    public function testNullIsValid(): void
    {
        $validator = Validation::createValidator();
        $violations = $validator->validate(null, [new CleanText()]);
        $this->assertCount(0, $violations);
    }

    public function testEmptyStringIsValid(): void
    {
        $validator = Validation::createValidator();
        $violations = $validator->validate('', [new CleanText()]);
        $this->assertCount(0, $violations);
    }

    public function testCleanTextIsValid(): void
    {
        $validator = Validation::createValidator();
        $violations = $validator->validate('Texto limpio sobre fontanería', [new CleanText()]);
        $this->assertCount(0, $violations);
    }

    public function testBadWordTriggersViolation(): void
    {
        $validator = Validation::createValidator();
        $violations = $validator->validate('Necesito un puta fontanero', [new CleanText()]);
        $this->assertCount(1, $violations);
        $this->assertStringContainsString('inapropiado', (string) $violations->get(0)->getMessage());
    }

    public function testBadWordWithAccentsTriggersViolation(): void
    {
        $validator = Validation::createValidator();
        $violations = $validator->validate('Eres un imbécil', [new CleanText()]);
        $this->assertCount(1, $violations);
    }
}
