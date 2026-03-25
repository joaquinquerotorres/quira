<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute]
class CleanText extends Constraint
{
    public string $message = 'El texto contiene lenguaje inapropiado o ofensivo. Por favor, sé respetuoso.';
}