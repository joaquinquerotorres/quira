<?php

declare(strict_types=1);

namespace App\Message;

final class AnalyzePredictMessage
{
    public function __construct(
        public readonly int $predictTaskId,
    ) {
    }
}
