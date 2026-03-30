<?php

declare(strict_types=1);

namespace App\Validator;

final class CifValidator
{
    /**
     * Valida un CIF español (9 caracteres) según el algoritmo estándar.
     *
     * Referencia (algoritmo y reglas de control):
     * - Suma A (posiciones pares) + B (posiciones impares duplicadas y ajustadas)
     * - Dígito E = (A+B) % 10; D = 0 si E=0, si no D = 10-E
     * - Letra de control: JABCDEFGHI (J para 0)
     * - Control numérico: A, B, E, H
     * - Control alfabético: P,Q,R,S,W,N (o provincia "00" = No residente)
     * - Otros tipos: control puede ser numérico o alfabético
     */
    public static function isValidCif(string $cif): bool
    {
        $cif = self::normalize($cif);
        if (!preg_match('/^[A-Z][0-9]{7}[0-9A-Z]$/', $cif)) {
            return false;
        }

        $typeLetter = $cif[0];
        $digits = substr($cif, 1, 7); // 7 dígitos centrales
        $controlChar = $cif[8];

        // A: suma de posiciones pares (2ª, 4ª, 6ª dentro de los 7 dígitos)
        // B: para posiciones impares (1ª, 3ª, 5ª, 7ª dentro de los 7 dígitos):
        //    duplicar y sumar dígitos (equivale a restar 9 si el resultado >= 10)
        $sumEven = 0;
        $sumOdd = 0;

        $digitArr = str_split($digits);
        // índices relativos 0..6
        foreach ($digitArr as $idx => $ch) {
            $d = (int) $ch;
            if ($idx % 2 === 0) {
                // posiciones impares (relativas): 0,2,4,6 -> duplicar
                $x = $d * 2;
                if ($x >= 10) {
                    $x -= 9;
                }
                $sumOdd += $x;
            } else {
                // posiciones pares: 1,3,5
                $sumEven += $d;
            }
        }

        $c = $sumEven + $sumOdd;
        $e = $c % 10;
        $d = $e === 0 ? 0 : (10 - $e);

        $expectedLetter = self::indexToControlLetter($d);
        $expectedDigit = (string) $d;

        $provinceCode = substr($cif, 1, 2); // "00" = No residente
        $isAlphabeticType = in_array($typeLetter, ['P', 'Q', 'R', 'S', 'W', 'N'], true) || $provinceCode === '00';
        $isNumericType = in_array($typeLetter, ['A', 'B', 'E', 'H'], true);

        if ($isNumericType) {
            // Debe ser numérico y coincidir con D
            return ctype_digit($controlChar) && $controlChar === $expectedDigit;
        }

        if ($isAlphabeticType) {
            // Debe ser alfabético y coincidir con la letra
            return ctype_alpha($controlChar) && $controlChar === $expectedLetter;
        }

        // Otros tipos: admite tanto dígito como letra.
        if (ctype_digit($controlChar)) {
            return $controlChar === $expectedDigit;
        }

        return $controlChar === $expectedLetter;
    }

    private static function normalize(string $cif): string
    {
        $cif = strtoupper(trim($cif));
        $cif = preg_replace('/\\s+/', '', $cif) ?? $cif;
        return $cif;
    }

    private static function indexToControlLetter(int $d): string
    {
        // índice 0..9 -> JABCDEFGHI
        $map = 'JABCDEFGHI';
        return $map[$d] ?? '';
    }
}

