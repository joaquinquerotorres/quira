<?php

declare(strict_types=1);

namespace App\Service;

final class PhoneComparisonService
{
    public function areEquivalent(?string $first, ?string $second): bool
    {
        $a = $this->normalizeForComparison($first);
        $b = $this->normalizeForComparison($second);

        return $a !== null && $b !== null && $a === $b;
    }

    public function normalizeForComparison(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        $digits = preg_replace('/\D/', '', $phone);
        if (!is_string($digits) || $digits === '') {
            return null;
        }

        // Regla ES: comparar últimos 9 dígitos (admite +34... y formato local).
        return strlen($digits) <= 9 ? $digits : substr($digits, -9);
    }
}

