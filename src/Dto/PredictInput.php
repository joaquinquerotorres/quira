<?php

declare(strict_types=1);

namespace App\Dto;

use App\Service\PredictMediaLimits;
use Symfony\Component\Validator\Constraints as Assert;

class PredictInput
{
    public function __construct(
        // Máximo alineado con Request::$clientOriginalDescription
        #[Assert\Length(max: 5000)]
        public readonly ?string $description = null,

        // Legacy: base64 / data URL (evitar en cliente; preferir *Url).
        #[Assert\Length(
            max: PredictMediaLimits::LEGACY_IMAGE_BASE64_CHARS,
            maxMessage: 'La imagen es demasiado grande (Máx 10MB)'
        )]
        public readonly ?string $image = null,

        #[Assert\Length(
            max: PredictMediaLimits::LEGACY_AUDIO_BASE64_CHARS,
            maxMessage: 'El audio es demasiado grande (Máx 12MB)'
        )]
        public readonly ?string $audio = null,

        #[Assert\Length(
            max: PredictMediaLimits::LEGACY_VIDEO_BASE64_CHARS,
            maxMessage: 'El video es demasiado grande (Máx 40MB)'
        )]
        public readonly ?string $video = null,

        #[Assert\Length(max: 255)]
        public readonly ?string $location = null,

        /** URL pública Supabase (preferido frente a image base64) */
        #[Assert\Length(max: 2048)]
        #[Assert\Url(protocols: ['https'])]
        public readonly ?string $imageUrl = null,

        #[Assert\Length(max: 2048)]
        #[Assert\Url(protocols: ['https'])]
        public readonly ?string $audioUrl = null,

        #[Assert\Length(max: 2048)]
        #[Assert\Url(protocols: ['https'])]
        public readonly ?string $videoUrl = null,
    ) {
    }

    public function hasUrlMedia(): bool
    {
        return $this->nonEmpty($this->imageUrl)
            || $this->nonEmpty($this->audioUrl)
            || $this->nonEmpty($this->videoUrl);
    }

    public function hasLegacyBase64Media(): bool
    {
        return $this->nonEmpty($this->image)
            || $this->nonEmpty($this->audio)
            || $this->nonEmpty($this->video);
    }

    public function hasContent(): bool
    {
        return $this->nonEmpty($this->description)
            || $this->hasUrlMedia()
            || $this->hasLegacyBase64Media();
    }

    private function nonEmpty(?string $value): bool
    {
        return $value !== null && trim($value) !== '';
    }
}
