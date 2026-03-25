<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute]
class NoContactInfo extends Constraint
{
    public string $message = 'Por razones de seguridad y políticas de la plataforma, no se permite información de contacto (números de teléfono o correos electrónicos) en preguntas públicas. Por favor, utiliza una propuesta formal.';
}