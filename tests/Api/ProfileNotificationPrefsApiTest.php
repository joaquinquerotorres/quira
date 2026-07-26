<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\ProfessionalProfile;
use PHPUnit\Framework\Attributes\Group;

#[Group('database')]
final class ProfileNotificationPrefsApiTest extends ApiTestCase
{
    public function testOwnerCanPatchProfessionalNotificationPrefs(): void
    {
        $pro = $this->createProfessionalUser(
            'notify-pro@test.com',
            ['ROLE_USER', 'ROLE_PROFESSIONAL', 'ROLE_PRO'],
            '+34600111222',
            true,
            null,
            null,
            0,
            true,
        );
        $profile = $pro->getProfessionalProfile();
        self::assertInstanceOf(ProfessionalProfile::class, $profile);
        $profile->setFullName('Taller Notificaciones Pro');
        $profile->setAddress('Calle Test 1, Córdoba');
        $this->em->flush();

        $profileId = $profile->getId();
        self::assertNotNull($profileId);

        $this->browser->request(
            'PATCH',
            '/api/professional_profiles/' . $profileId,
            server: array_merge(
                $this->authHeaders($pro),
                ['CONTENT_TYPE' => 'application/merge-patch+json', 'HTTP_ACCEPT' => 'application/ld+json']
            ),
            content: json_encode([
                'notifyRequestActivity' => false,
                'notifyBidActivity' => false,
                'notifyReviews' => true,
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(200);
        $data = $this->decodeJsonResponse((string) $this->browser->getResponse()->getContent());
        self::assertFalse($data['notifyRequestActivity']);
        self::assertFalse($data['notifyBidActivity']);
        self::assertTrue($data['notifyReviews']);

        $this->em->clear();
        /** @var ProfessionalProfile $reloaded */
        $reloaded = $this->em->find(ProfessionalProfile::class, $profileId);
        self::assertNotNull($reloaded);
        self::assertFalse($reloaded->getNotifyRequestActivity());
        self::assertFalse($reloaded->getNotifyBidActivity());
        self::assertTrue($reloaded->getNotifyReviews());
    }

    public function testUserReadIncludesProfessionalNotificationPrefs(): void
    {
        $pro = $this->createProfessionalUser(
            'notify-user-read@test.com',
            ['ROLE_USER', 'ROLE_PROFESSIONAL', 'ROLE_PRO'],
        );
        $profile = $pro->getProfessionalProfile();
        self::assertInstanceOf(ProfessionalProfile::class, $profile);
        $profile->setFullName('Taller Preferencias User Read');
        $profile->setAddress('Calle Test 1, Córdoba');
        $profile->setNotifyRequestActivity(false);
        $this->em->flush();

        $userId = $pro->getId();
        self::assertNotNull($userId);

        $this->browser->request(
            'GET',
            '/api/users/' . $userId,
            server: array_merge($this->authHeaders($pro), ['HTTP_ACCEPT' => 'application/ld+json']),
        );

        self::assertResponseIsSuccessful();
        $data = $this->decodeJsonResponse((string) $this->browser->getResponse()->getContent());
        self::assertIsArray($data['professionalProfile'] ?? null);
        self::assertFalse($data['professionalProfile']['notifyRequestActivity']);
        self::assertArrayHasKey('notifyBidActivity', $data['professionalProfile']);
        self::assertArrayHasKey('notifyReviews', $data['professionalProfile']);
    }
}
