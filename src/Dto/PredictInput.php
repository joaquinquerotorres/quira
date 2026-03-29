<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class PredictInput
{
    public function __construct(
        // Máximo alineado con Request::$clientOriginalDescription
        #[Assert\Length(max: 5000)]
        public readonly ?string $description = null,

        // ~7.5 MB file size limit
        #[Assert\Length(max: 10000000, maxMessage: " La imagen es demasiado grande (Máx 7MB)")] 
        public readonly ?string $image = null,

        // ~3.7 MB file size limit
        #[Assert\Length(max: 5000000, maxMessage: " El audio es demasiado grande (Máx 3MB)")]
        public readonly ?string $audio = null,

        // ~37 MB file size limit: 15-30s of video
        #[Assert\Length(max: 50000000, maxMessage: " El video es demasiado grande (Máx 35MB)")]
        public readonly ?string $video = null,

        #[Assert\Length(max: 255)]
        public readonly ?string $location = null,
    ) {
    }
}