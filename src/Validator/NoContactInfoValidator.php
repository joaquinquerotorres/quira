<?php

declare(strict_types=1);

namespace App\Validator;

use App\Service\ContactInfoDetector;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class NoContactInfoValidator extends ConstraintValidator
{
    public function __construct(
        private readonly ContactInfoDetector $contactInfoDetector = new ContactInfoDetector(),
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof NoContactInfo) {
            throw new UnexpectedTypeException($constraint, NoContactInfo::class);
        }

        if (!\is_string($value) && $value !== null) {
            return;
        }

        if ($this->contactInfoDetector->containsContactInfo(\is_string($value) ? $value : null)) {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }
}
