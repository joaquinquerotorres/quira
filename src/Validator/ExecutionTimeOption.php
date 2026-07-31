<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Presets de disponibilidad o "Fecha concreta: DD/MM/YYYY".
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
final class ExecutionTimeOption extends Constraint
{
    public const string FECHA_CONCRETA_PATTERN = '/^Fecha concreta: \d{2}\/\d{2}\/\d{4}$/';

    /** @var list<string> */
    public array $presets;

    public string $message;

    /**
     * @param list<string> $presets
     */
    public function __construct(
        array $presets,
        string $message = 'El valor de disponibilidad no es válido.',
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct([], $groups, $payload);
        $this->presets = $presets;
        $this->message = $message;
    }
}
