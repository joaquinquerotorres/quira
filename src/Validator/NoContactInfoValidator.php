<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class NoContactInfoValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof NoContactInfo) {
            throw new UnexpectedTypeException($constraint, NoContactInfo::class);
        }

        if (null === $value || '' === $value) {
            return;
        }

        if (preg_match('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $value)) {
            $this->context->buildViolation($constraint->message)->addViolation();
            return;
        }

        if (preg_match('/(\+34|0034|34)?[ -]*([679])[ -]*([0-9][ -]*){8}/', $value)) {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }
}