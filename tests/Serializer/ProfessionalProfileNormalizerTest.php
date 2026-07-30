<?php

declare(strict_types=1);

namespace App\Tests\Serializer;

use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Entity\ProfessionalProfile;
use App\Entity\User;
use App\Repository\ReviewRepository;
use App\Serializer\ProfessionalProfileNormalizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final class ProfessionalProfileNormalizerTest extends TestCase
{
    public function testNormalizeInjectsReviewsOnItemProRead(): void
    {
        $user = new User();
        $user->setEmail('pro@test.com');
        $profile = new ProfessionalProfile();
        $profile->setFullName('Pro');
        $profile->setUser($user);
        $user->setProfessionalProfile($profile);

        $reviews = [
            ['id' => 1, 'score' => 5, 'comment' => 'Ok', 'authorName' => 'Cliente', 'createdAt' => '2026-01-01T00:00:00+00:00'],
        ];

        $repo = $this->createMock(ReviewRepository::class);
        $repo->expects($this->once())
            ->method('findRecentSerializedForProfessionalProfile')
            ->with($profile)
            ->willReturn($reviews);

        $inner = $this->createMock(NormalizerInterface::class);
        $inner->method('normalize')->willReturn([
            '@id' => '/api/professional_profiles/1',
            'fullName' => 'Pro',
            'assignedRequests' => [['id' => 99]],
        ]);
        $inner->method('getSupportedTypes')->willReturn([]);

        $normalizer = new ProfessionalProfileNormalizer($inner, $repo);
        $result = $normalizer->normalize($profile, null, [
            'groups' => ['pro:read'],
            'operation' => new Get(),
        ]);

        $this->assertIsArray($result);
        $this->assertSame($reviews, $result['reviews']);
        $this->assertArrayNotHasKey('assignedRequests', $result);
    }

    public function testNormalizeSkipsReviewsOnCollection(): void
    {
        $profile = new ProfessionalProfile();
        $profile->setFullName('Pro');

        $repo = $this->createMock(ReviewRepository::class);
        $repo->expects($this->never())->method('findRecentSerializedForProfessionalProfile');

        $inner = $this->createMock(NormalizerInterface::class);
        $inner->method('normalize')->willReturn([
            'fullName' => 'Pro',
            'assignedRequests' => [],
        ]);
        $inner->method('getSupportedTypes')->willReturn([]);

        $normalizer = new ProfessionalProfileNormalizer($inner, $repo);
        $result = $normalizer->normalize($profile, null, [
            'groups' => ['pro:read'],
            'operation' => new GetCollection(),
        ]);

        $this->assertIsArray($result);
        $this->assertArrayNotHasKey('reviews', $result);
        $this->assertArrayNotHasKey('assignedRequests', $result);
    }

    public function testNormalizeDelegatesForNonProfile(): void
    {
        $inner = $this->createMock(NormalizerInterface::class);
        $inner->method('normalize')->willReturn(['ok' => true]);
        $inner->method('getSupportedTypes')->willReturn([]);
        $repo = $this->createStub(ReviewRepository::class);

        $normalizer = new ProfessionalProfileNormalizer($inner, $repo);
        $this->assertSame(['ok' => true], $normalizer->normalize(new \stdClass()));
    }
}
