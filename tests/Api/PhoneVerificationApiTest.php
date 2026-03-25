<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\ClientProfile;
use App\Entity\ProfessionalProfile;
use App\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Contracts\Cache\CacheInterface;

#[Group('database')]
final class PhoneVerificationApiTest extends ApiTestCase
{
    public function testSendThenConfirmVerifiesBothProfilesWhenPhoneMatches(): void
    {
        $user = new User();
        $user->setEmail('phone-verify@test.com');
        $user->setPassword('hashed');
        $user->setRoles(['ROLE_USER']);
        $this->em->persist($user);
        $this->em->flush();

        $client = new ClientProfile();
        $client->setFullName('Client');
        $client->setPhoneNumber('+34 600 000 300');
        $client->setVerifiedPhone(false);
        $client->setUser($user);
        $user->setClientProfile($client);
        $this->em->persist($client);
        $this->em->flush();
        $clientId = (int) $client->getId();

        $pro = new ProfessionalProfile();
        $pro->setFullName('Pro');
        $pro->setPhoneNumber('600000300'); // same number, different format
        $pro->setVerifiedPhone(false);
        $pro->setUser($user);
        $user->setProfessionalProfile($pro);
        $this->em->persist($pro);

        $this->em->flush();
        $proId = (int) $pro->getId();

        // Send OTP for client profile
        $this->browser->request(
            'POST',
            '/api/verify/phone/send',
            [],
            [],
            array_merge($this->authHeaders($user), ['CONTENT_TYPE' => 'application/json']),
            json_encode(['profile' => 'client'], JSON_THROW_ON_ERROR)
        );
        $this->assertResponseIsSuccessful();

        // Read OTP from cache (sandbox) using same key logic as PhoneVerificationService
        /** @var CacheInterface $cache */
        $cache = static::getContainer()->get(CacheInterface::class);
        $normalized = '+34600000300';
        $hash = hash('sha256', $normalized . '_' . $user->getId());
        $cacheKey = 'otp_' . $hash;
        $code = $cache->get($cacheKey, fn () => null);
        $this->assertIsString($code);
        $this->assertNotEmpty($code);

        // Confirm OTP using professional profile - should verify both since same phone
        $this->browser->request(
            'POST',
            '/api/verify/phone/confirm',
            [],
            [],
            array_merge($this->authHeaders($user), ['CONTENT_TYPE' => 'application/json']),
            json_encode(['profile' => 'professional', 'code' => $code], JSON_THROW_ON_ERROR)
        );
        $this->assertResponseIsSuccessful();

        /** @var ClientProfile $clientUpdated */
        $clientUpdated = $this->em->getRepository(ClientProfile::class)->find($clientId);
        /** @var ProfessionalProfile $proUpdated */
        $proUpdated = $this->em->getRepository(ProfessionalProfile::class)->find($proId);
        $this->assertNotNull($clientUpdated);
        $this->assertNotNull($proUpdated);
        $this->assertTrue($clientUpdated->isVerifiedPhone());
        $this->assertTrue($proUpdated->isVerifiedPhone());
    }

    public function testSendSkipsWhenOtherProfileAlreadyVerifiedSamePhone(): void
    {
        $user = new User();
        $user->setEmail('phone-skip@test.com');
        $user->setPassword('hashed');
        $user->setRoles(['ROLE_USER']);
        $this->em->persist($user);
        $this->em->flush();

        $client = new ClientProfile();
        $client->setFullName('Client');
        $client->setPhoneNumber('600000301');
        $client->setVerifiedPhone(true);
        $client->setUser($user);
        $user->setClientProfile($client);
        $this->em->persist($client);

        $pro = new ProfessionalProfile();
        $pro->setFullName('Pro');
        $pro->setPhoneNumber('+34 600 000 301');
        $pro->setVerifiedPhone(false);
        $pro->setUser($user);
        $user->setProfessionalProfile($pro);
        $this->em->persist($pro);
        $this->em->flush();

        $this->browser->request(
            'POST',
            '/api/verify/phone/send',
            [],
            [],
            array_merge($this->authHeaders($user), ['CONTENT_TYPE' => 'application/json']),
            json_encode(['profile' => 'professional'], JSON_THROW_ON_ERROR)
        );
        $this->assertResponseIsSuccessful();

        $data = $this->decodeJsonResponse($this->browser->getResponse()->getContent());
        $this->assertTrue($data['success']);
        $this->assertTrue($data['skipped']);
        $this->assertSame('same_number_already_verified', $data['reason']);
    }
}

