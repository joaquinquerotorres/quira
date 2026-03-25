<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class CleanTextValidator extends ConstraintValidator
{
    private const BAD_WORDS = [
        'puta', 'puto', 'mierda', 'cabron', 'cabrón', 'gilipollas', 'capullo',
        'subnormal', 'idiota', 'imbécil', 'imbecil', 'follar', 'joder', 'coño',
        'maricon', 'maricón', 'zorra', 'bastardo', 'payaso', 'estupido', 'estúpido',
        'verga', 'pene', 'polla', 'chupar', 'lamer', 'culo', 'fuck', 'shit', 'bitch'
    ];

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof CleanText) {
            throw new UnexpectedTypeException($constraint, CleanText::class);
        }

        if (null === $value || '' === $value) {
            return;
        }

        $normalizedText = $this->normalize($value);

        foreach (self::BAD_WORDS as $badWord) {
            $pattern = '/\b' . preg_quote($badWord, '/') . '\b/iu';

            if (preg_match($pattern, $normalizedText)) {
                $this->context->buildViolation($constraint->message)
                    ->addViolation();
                return; 
            }
        }
    }

    private function normalize(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = str_replace(['á','é','í','ó','ú'], ['a','e','i','o','u'], $text);
        return $text;
    }
}