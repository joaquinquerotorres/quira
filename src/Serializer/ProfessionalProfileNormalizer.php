<?php

declare(strict_types=1);

namespace App\Serializer;

use ApiPlatform\Metadata\GetCollection;
use App\Entity\ProfessionalProfile;
use App\Repository\ReviewRepository;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerAwareInterface;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Inyecta `reviews` con query acotada (evita hidratar reviewsReceived + filtrar en PHP).
 * Solo en detalle (no GetCollection del directorio) para no N+1 en listados.
 * `completedJobs` sigue saliendo del getter EXTRA_LAZY/COUNT.
 * No serializa `assignedRequests` (quitado de grupos).
 */
final class ProfessionalProfileNormalizer implements NormalizerInterface, DenormalizerInterface, SerializerAwareInterface
{
    public function __construct(
        private readonly NormalizerInterface $inner,
        private readonly ReviewRepository $reviewRepository,
    ) {
    }

    public function setSerializer(SerializerInterface $serializer): void
    {
        if ($this->inner instanceof SerializerAwareInterface) {
            $this->inner->setSerializer($serializer);
        }
    }

    public function normalize(mixed $object, ?string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
    {
        $data = $this->inner->normalize($object, $format, $context);

        if (!$object instanceof ProfessionalProfile || !\is_array($data)) {
            return $data;
        }

        unset($data['assignedRequests']);

        $groups = $context['groups'] ?? [];
        $groups = \is_array($groups) ? $groups : [];
        $wantsProRead = $groups === []
            || \in_array('pro:read', $groups, true)
            || \in_array('user:read', $groups, true);

        if (!$wantsProRead || $this->isCollectionOperation($context)) {
            return $data;
        }

        $data['reviews'] = $this->reviewRepository->findRecentSerializedForProfessionalProfile($object);

        return $data;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function isCollectionOperation(array $context): bool
    {
        $operation = $context['operation'] ?? null;
        if ($operation instanceof GetCollection) {
            return true;
        }

        return ($context['operation_type'] ?? null) === 'collection';
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $this->inner->supportsNormalization($data, $format, $context);
    }

    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed
    {
        if ($this->inner instanceof DenormalizerInterface) {
            return $this->inner->denormalize($data, $type, $format, $context);
        }
        throw new \BadMethodCallException('Inner normalizer does not support denormalization.');
    }

    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return $this->inner instanceof DenormalizerInterface
            && $this->inner->supportsDenormalization($data, $type, $format, $context);
    }

    public function getSupportedTypes(?string $format): array
    {
        $types = $this->inner->getSupportedTypes($format);

        return [ProfessionalProfile::class => false] + (\is_array($types) ? $types : []);
    }
}
