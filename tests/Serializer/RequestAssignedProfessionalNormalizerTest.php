<?php

declare(strict_types=1);

namespace App\Tests\Serializer;

use App\Entity\ClientProfile;
use App\Entity\ProfessionalProfile;
use App\Entity\Request;
use App\Entity\User;
use App\Entity\VisitRequest;
use App\Repository\VisitRequestRepository;
use App\Security\Voter\RequestAddressVoter;
use App\Serializer\RequestAssignedProfessionalNormalizer;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerInterface;

final class RequestAssignedProfessionalNormalizerTest extends TestCase
{
    private NormalizerInterface $inner;

    private Security $security;

    private VisitRequestRepository $visitRequestRepository;

    protected function setUp(): void
    {
        $this->inner = $this->createMock(NormalizerInterface::class);
        $this->inner->method('getSupportedTypes')->willReturn([]);
        $this->security = $this->createStub(Security::class);
        $this->security->method('isGranted')->willReturn(true);
        $this->visitRequestRepository = $this->createStub(VisitRequestRepository::class);
        $this->visitRequestRepository->method('findOneBy')->willReturn(null);
    }

    private function createNormalizer(): RequestAssignedProfessionalNormalizer
    {
        return new RequestAssignedProfessionalNormalizer($this->inner, $this->security, $this->visitRequestRepository);
    }

    public function testNormalizeAddsPhoneNumberToAssignedProfessional(): void
    {
        $proUser = new User();
        $proUser->setEmail('pro@test.com');
        $proUser->setPassword('hash');

        $proProfile = new ProfessionalProfile();
        $proProfile->setFullName('Pro Test');
        $proProfile->setPhoneNumber('+34600111222');
        $proProfile->setUser($proUser);
        $proUser->setProfessionalProfile($proProfile);

        $clientUser = new User();
        $clientUser->setEmail('client@test.com');
        $clientUser->setPassword('hash');
        $clientProfile = new ClientProfile();
        $clientProfile->setFullName('Client');
        $clientProfile->setUser($clientUser);
        $clientUser->setClientProfile($clientProfile);

        $request = new Request();
        $request->setTitle('Test request');
        $request->setAddress('Calle 1');
        $request->setEstimatedPriceMin(100);
        $request->setEstimatedPriceMax(100);
        $request->setClient($clientProfile);
        $request->setAssignedProfessional($proProfile);

        $innerResult = [
            '@id' => '/api/requests/1',
            'assignedProfessional' => [
                '@id' => '/api/professional_profiles/92',
                '@type' => 'ProfessionalProfile',
                'fullName' => 'Pro Test',
            ],
        ];

        $this->inner->method('normalize')
            ->with($request, null, [])
            ->willReturn($innerResult);
        $this->inner->method('supportsNormalization')->willReturn(true);

        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturn(true);
        $security->method('getUser')->willReturn($clientUser);

        $normalizer = new RequestAssignedProfessionalNormalizer($this->inner, $security, $this->visitRequestRepository);
        $result = $normalizer->normalize($request);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('assignedProfessional', $result);
        $this->assertSame('+34600111222', $result['assignedProfessional']['phoneNumber']);
    }

    public function testNormalizeDoesNotAddAssignedProfessionalPhoneForOutsider(): void
    {
        $proUser = new User();
        $proUser->setEmail('pro@test.com');
        $proUser->setPassword('hash');

        $proProfile = new ProfessionalProfile();
        $proProfile->setFullName('Pro Test');
        $proProfile->setPhoneNumber('+34600111222');
        $proProfile->setUser($proUser);
        $proUser->setProfessionalProfile($proProfile);

        $clientUser = new User();
        $clientUser->setEmail('client@test.com');
        $clientProfile = new ClientProfile();
        $clientProfile->setFullName('Client');
        $clientProfile->setUser($clientUser);

        $outsider = new User();
        $outsider->setEmail('outsider@test.com');

        $request = new Request();
        $request->setTitle('Test request');
        $request->setAddress('Calle 1');
        $request->setEstimatedPriceMin(100);
        $request->setEstimatedPriceMax(100);
        $request->setClient($clientProfile);
        $request->setAssignedProfessional($proProfile);

        $innerResult = [
            '@id' => '/api/requests/1',
            'assignedProfessional' => [
                '@id' => '/api/professional_profiles/92',
                'fullName' => 'Pro Test',
                'phoneNumber' => '+34600111222',
            ],
        ];

        $this->inner->method('normalize')->willReturn($innerResult);
        $this->inner->method('supportsNormalization')->willReturn(true);

        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturn(true);
        $security->method('getUser')->willReturn($outsider);

        $normalizer = new RequestAssignedProfessionalNormalizer($this->inner, $security, $this->visitRequestRepository);
        $result = $normalizer->normalize($request);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('assignedProfessional', $result);
        $this->assertArrayNotHasKey('phoneNumber', $result['assignedProfessional']);
    }

    public function testNormalizeDoesNotAddPhoneWhenAssignedProfessionalIsNull(): void
    {
        $clientProfile = new ClientProfile();
        $clientProfile->setFullName('Client');
        $clientProfile->setUser(new User());

        $request = new Request();
        $request->setTitle('Test request');
        $request->setAddress('Calle 1');
        $request->setEstimatedPriceMin(100);
        $request->setEstimatedPriceMax(100);
        $request->setClient($clientProfile);
        $request->setAssignedProfessional(null);

        $innerResult = [
            '@id' => '/api/requests/1',
            'assignedProfessional' => null,
        ];

        $this->inner->method('normalize')
            ->with($request, null, [])
            ->willReturn($innerResult);
        $this->inner->method('supportsNormalization')->willReturn(true);

        $normalizer = $this->createNormalizer();
        $result = $normalizer->normalize($request);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('assignedProfessional', $result);
        $this->assertNull($result['assignedProfessional']);
    }

    public function testNormalizeDelegatesForNonRequest(): void
    {
        $other = new \stdClass();
        $innerResult = ['@type' => 'Something'];

        $this->inner->method('normalize')
            ->with($other, null, [])
            ->willReturn($innerResult);
        $this->inner->method('supportsNormalization')->willReturn(true);

        $normalizer = $this->createNormalizer();
        $result = $normalizer->normalize($other);

        $this->assertSame($innerResult, $result);
    }

    public function testNormalizeDoesNotBreakWhenAssignedProfessionalKeyMissing(): void
    {
        $clientProfile = new ClientProfile();
        $clientProfile->setFullName('Client');
        $clientProfile->setUser(new User());

        $request = new Request();
        $request->setTitle('Test');
        $request->setAddress('Calle');
        $request->setEstimatedPriceMin(50);
        $request->setEstimatedPriceMax(50);
        $request->setClient($clientProfile);
        $request->setAssignedProfessional(null);

        $innerResult = ['@id' => '/api/requests/1'];
        $this->inner->method('normalize')->willReturn($innerResult);
        $this->inner->method('supportsNormalization')->willReturn(true);

        $normalizer = $this->createNormalizer();
        $result = $normalizer->normalize($request);

        $this->assertSame($innerResult, $result);
    }

    public function testSupportsNormalizationDelegatesToInner(): void
    {
        $this->inner->method('supportsNormalization')
            ->with($this->anything(), null, [])
            ->willReturn(true);

        $normalizer = $this->createNormalizer();
        $this->assertTrue($normalizer->supportsNormalization(new Request()));
    }

    public function testGetSupportedTypesIncludesRequestAndDelegatesToInner(): void
    {
        $this->inner->method('getSupportedTypes')
            ->willReturn([]);

        $normalizer = $this->createNormalizer();
        $types = $normalizer->getSupportedTypes(null);

        $this->assertArrayHasKey(Request::class, $types);
        $this->assertFalse($types[Request::class]);
    }

    public function testSetSerializerDoesNotThrowWhenInnerNotSerializerAware(): void
    {
        $serializer = $this->createStub(SerializerInterface::class);
        $normalizer = $this->createNormalizer();
        $normalizer->setSerializer($serializer);
        $this->addToAssertionCount(1);
    }

    public function testDenormalizeThrowsWhenInnerDoesNotSupportDenormalization(): void
    {
        $this->inner->method('getSupportedTypes')->willReturn([]);
        $normalizer = $this->createNormalizer();

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('does not support denormalization');
        $normalizer->denormalize([], Request::class);
    }

    public function testDenormalizeDelegatesToInnerWhenItImplementsDenormalizerInterface(): void
    {
        $expected = new Request();
        $inner = new class($expected) implements NormalizerInterface, DenormalizerInterface {
            public function __construct(private readonly Request $toReturn) {}

            public function normalize(mixed $object, ?string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
            {
                return [];
            }

            public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
            {
                return false;
            }

            public function getSupportedTypes(?string $format): array
            {
                return [];
            }

            public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed
            {
                return $this->toReturn;
            }

            public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
            {
                return true;
            }
        };
        $normalizer = new RequestAssignedProfessionalNormalizer($inner, $this->security, $this->visitRequestRepository);
        $result = $normalizer->denormalize(['title' => 'Test'], Request::class);
        $this->assertSame($expected, $result);
    }

    public function testSupportsDenormalizationReturnsFalseWhenInnerDoesNotImplementDenormalizerInterface(): void
    {
        $normalizer = $this->createNormalizer();
        $this->assertFalse($normalizer->supportsDenormalization([], Request::class));
    }

    public function testNormalizeRemovesPreciseAddressWhenNotGranted(): void
    {
        $request = new Request();
        $request->setTitle('Test');
        $request->setAddress('Calle');
        $request->setEstimatedPriceMin(100);
        $request->setEstimatedPriceMax(100);
        $request->setPreciseAddress('Avenida de Libia - 53');
        $request->setClient(new ClientProfile());

        $innerResult = [
            '@id' => '/api/requests/1',
            'preciseAddress' => 'Avenida de Libia - 53',
        ];
        $this->inner->method('normalize')->willReturn($innerResult);
        $this->inner->method('supportsNormalization')->willReturn(true);

        $security = $this->createMock(Security::class);
        $security->method('isGranted')->with(RequestAddressVoter::VIEW_PRECISE_ADDRESS, $request)->willReturn(false);
        $normalizer = new RequestAssignedProfessionalNormalizer($this->inner, $security, $this->visitRequestRepository);
        $result = $normalizer->normalize($request);

        $this->assertArrayNotHasKey('preciseAddress', $result);
    }

    public function testNormalizeRemovesClientPhoneWhenUserNotAssignedAndNoAcceptedVisit(): void
    {
        $clientProfile = new ClientProfile();
        $clientProfile->setFullName('Client');
        $clientProfile->setPhoneNumber('+34600000000');
        $clientProfile->setUser(new User());
        $request = new Request();
        $request->setTitle('Test');
        $request->setAddress('Calle');
        $request->setEstimatedPriceMin(100);
        $request->setEstimatedPriceMax(100);
        $request->setClient($clientProfile);
        $request->setAssignedProfessional(null);

        $innerResult = [
            '@id' => '/api/requests/1',
            'client' => [
                '@id' => '/api/client_profiles/1',
                'fullName' => 'Client',
                'phoneNumber' => '+34600000000',
            ],
        ];
        $this->inner->method('normalize')->willReturn($innerResult);
        $this->inner->method('supportsNormalization')->willReturn(true);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn(new User());
        $normalizer = new RequestAssignedProfessionalNormalizer($this->inner, $security, $this->visitRequestRepository);
        $result = $normalizer->normalize($request);

        $this->assertArrayHasKey('client', $result);
        $this->assertIsArray($result['client']);
        $this->assertArrayNotHasKey('phoneNumber', $result['client']);
    }

    public function testNormalizeInjectsClientAvatarRatingReviewCount(): void
    {
        $clientProfile = new ClientProfile();
        $clientProfile->setFullName('Client');
        $clientProfile->setAvatar('https://example.com/avatar.jpg');
        $clientProfile->setRating(4.5);
        $clientProfile->setReviewCount(12);
        $clientProfile->setUser(new User());
        $request = new Request();
        $request->setTitle('Test');
        $request->setAddress('Calle');
        $request->setEstimatedPriceMin(100);
        $request->setEstimatedPriceMax(100);
        $request->setClient($clientProfile);

        $innerResult = [
            '@id' => '/api/requests/1',
            'client' => [
                '@id' => '/api/client_profiles/1',
                'fullName' => 'Client',
            ],
        ];
        $this->inner->method('normalize')->willReturn($innerResult);
        $this->inner->method('supportsNormalization')->willReturn(true);

        $normalizer = $this->createNormalizer();
        $result = $normalizer->normalize($request);

        $this->assertArrayHasKey('client', $result);
        $this->assertSame('https://example.com/avatar.jpg', $result['client']['avatar']);
        $this->assertSame(4.5, $result['client']['rating']);
        $this->assertSame(12, $result['client']['reviewCount']);
    }

    public function testNormalizeShowsClientPhoneUsingInMemoryAcceptedVisitWithoutFindOneBy(): void
    {
        $clientProfile = new ClientProfile();
        $clientProfile->setFullName('Client');
        $clientProfile->setPhoneNumber('+34600000000');
        $clientProfile->setUser(new User());

        $proUser = new User();
        $proUser->setEmail('pro@test.com');
        $proProfile = new ProfessionalProfile();
        $proProfile->setFullName('Pro');
        $this->setEntityId($proProfile, 42);
        $proProfile->setUser($proUser);
        $proUser->setProfessionalProfile($proProfile);

        $request = new Request();
        $request->setTitle('Test');
        $request->setAddress('Calle');
        $request->setEstimatedPriceMin(100);
        $request->setEstimatedPriceMax(100);
        $request->setClient($clientProfile);

        $visit = new VisitRequest();
        $visit->setRequest($request);
        $visit->setProfessional($proProfile);
        $visit->setStatus(VisitRequest::STATUS_ACCEPTED);
        $request->getVisitRequests()->add($visit);

        $innerResult = [
            '@id' => '/api/requests/1',
            'client' => [
                'fullName' => 'Client',
                'phoneNumber' => '+34600000000',
            ],
        ];
        $this->inner->method('normalize')->willReturn($innerResult);
        $this->inner->method('supportsNormalization')->willReturn(true);

        $visitRepo = $this->createMock(VisitRequestRepository::class);
        $visitRepo->expects($this->never())->method('findOneBy');

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($proUser);
        $security->method('isGranted')->willReturn(true);

        $normalizer = new RequestAssignedProfessionalNormalizer($this->inner, $security, $visitRepo);
        $result = $normalizer->normalize($request);

        $this->assertIsArray($result);
        $this->assertSame('+34600000000', $result['client']['phoneNumber']);
    }

    private function setEntityId(object $entity, int $id): void
    {
        $ref = new \ReflectionClass($entity);
        $prop = $ref->getProperty('id');
        $prop->setValue($entity, $id);
    }
}
