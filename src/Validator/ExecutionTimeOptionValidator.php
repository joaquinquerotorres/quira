<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class ExecutionTimeOptionValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ExecutionTimeOption) {
            throw new UnexpectedTypeException($constraint, ExecutionTimeOption::class);
        }

        if ($value === null || $value === '') {
            return;
        }

        if (!\is_string($value)) {
            throw new UnexpectedValueException($value, 'string');
        }

        if (\in_array($value, $constraint->presets, true)) {
            return;
        }

        if (preg_match(ExecutionTimeOption::FECHA_CONCRETA_PATTERN, $value) === 1) {
            return;
        }

        $this->context->buildViolation($constraint->message)->addViolation();
    }
}
