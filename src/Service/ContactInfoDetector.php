<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Detección heurística (texto) de email / teléfono ES.
 * Complementa la moderación LLM; no sustituye el análisis multimodal.
 */
final class ContactInfoDetector
{
    private const EMAIL_PATTERN = '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/';

    /** Móvil/fijo ES: opcional +34/0034/34 + 9 dígitos empezando por 6/7/9. */
    private const PHONE_PATTERN = '/(\+34|0034|34)?[ -]*([679])[ -]*([0-9][ -]*){8}/';

    public function containsContactInfo(?string $text): bool
    {
        return $this->detectReason($text) !== null;
    }

    /**
     * @return string|null Motivo breve si hay contacto; null si el texto está limpio.
     */
    public function detectReason(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        if (preg_match(self::EMAIL_PATTERN, $text) === 1) {
            return 'Se detectó un email en el texto.';
        }

        if (preg_match(self::PHONE_PATTERN, $text) === 1) {
            return 'Se detectó un teléfono en el texto.';
        }

        return null;
    }
}
