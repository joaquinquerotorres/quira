<?php

declare(strict_types=1);

namespace App\Serializer;

use LongitudeOne\Spatial\PHP\Types\Geometry\Point;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

class PointDenormalizer implements DenormalizerInterface
{
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed
    {
        if (isset($data['coordinates']) && is_array($data['coordinates']) && count($data['coordinates']) === 2) {
            return new Point($data['coordinates'][0], $data['coordinates'][1]);
        }

        if (isset($data['x'], $data['y'])) {
            return new Point($data['x'], $data['y']);
        }

        throw new \InvalidArgumentException('El formato del punto es incorrecto. Se espera GeoJSON: {"type":"Point", "coordinates":[lon, lat]}');
    }

    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return $type === Point::class;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [
            Point::class => true,
        ];
    }
}