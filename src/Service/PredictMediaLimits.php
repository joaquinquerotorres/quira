<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Límites de tamaño para media de predict / request (flujo URL → Supabase).
 *
 * El cliente debe usar estos valores de forma uniforme (Wi‑Fi y datos móviles).
 * El antiguo tope ~25 MB en no‑Wi‑Fi aplicaba al POST legacy con base64 en el body;
 * con Messenger la subida va a Supabase y el worker descarga hasta estos máximos.
 */
final class PredictMediaLimits
{
    public const IMAGE_BYTES = 10_000_000;
    public const AUDIO_BYTES = 12_000_000;
    public const VIDEO_BYTES = 40_000_000;

    /**
     * Longitud máxima de string base64/Data URL en PredictInput legacy
     * (~ ceil(bytes * 4/3) con margen para el prefijo data:…).
     */
    public const LEGACY_IMAGE_BASE64_CHARS = 14_000_000;
    public const LEGACY_AUDIO_BASE64_CHARS = 17_000_000;
    public const LEGACY_VIDEO_BASE64_CHARS = 56_000_000;

    /**
     * @param 'photo'|'image'|'audio'|'video' $type
     */
    public static function maxBytesFor(string $type): int
    {
        return match ($type) {
            'photo', 'image' => self::IMAGE_BYTES,
            'audio' => self::AUDIO_BYTES,
            'video' => self::VIDEO_BYTES,
        };
    }

    /**
     * @return array{image: int, audio: int, video: int}
     */
    public static function all(): array
    {
        return [
            'image' => self::IMAGE_BYTES,
            'audio' => self::AUDIO_BYTES,
            'video' => self::VIDEO_BYTES,
        ];
    }
}
