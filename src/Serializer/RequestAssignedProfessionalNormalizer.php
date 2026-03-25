<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Entity\Request;
use App\Entity\User;
use App\Entity\VisitRequest;
use App\Repository\VisitRequestRepository;
use App\Security\Voter\RequestAddressVoter;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerAwareInterface;
use Symfony\Component\Serializer\SerializerInterface;

final class RequestAssignedProfessionalNormalizer implements NormalizerInterface, DenormalizerInterface, SerializerAwareInterface
{
    public function __construct(
        private readonly NormalizerInterface $inner,
        private readonly Security $security,
        private readonly VisitRequestRepository $visitRequestRepository,
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

        if (!$object instanceof Request || !\is_array($data)) {
            return $data;
        }

        $assigned = $object->getAssignedProfessional();
        if ($assigned !== null && isset($data['assignedProfessional']) && \is_array($data['assignedProfessional'])) {
            $data['assignedProfessional']['phoneNumber'] = $assigned->getPhoneNumber();
        }

        if (!$this->security->isGranted(RequestAddressVoter::VIEW_PRECISE_ADDRESS, $object)) {
            unset($data['preciseAddress']);
        }

        if (isset($data['client']) && \is_array($data['client'])) {
            $clientProfile = $object->getClient();
            if ($clientProfile !== null) {
                $data['client']['avatar'] = $clientProfile->getAvatar();
                $data['client']['rating'] = $clientProfile->getRating();
                $data['client']['reviewCount'] = $clientProfile->getReviewCount();
            }
            if (!$this->canViewClientPhone($object)) {
                unset($data['client']['phoneNumber']);
            }
        }

        return $data;
    }

    private function canViewClientPhone(Request $request): bool
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return false;
        }
        $assigned = $request->getAssignedProfessional();
        if ($assigned !== null && $assigned->getUser() === $user) {
            return true;
        }
        $proProfile = $user->getProfessionalProfile();
        if ($proProfile === null) {
            return false;
        }
        $visit = $this->visitRequestRepository->findOneBy([
            'request' => $request,
            'professional' => $proProfile,
            'status' => VisitRequest::STATUS_ACCEPTED,
        ]);

        return $visit !== null;
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
        return [Request::class => false] + (is_array($types) ? $types : []);
    }
}
