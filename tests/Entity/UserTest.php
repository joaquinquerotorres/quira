<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ClientProfile;
use App\Entity\ProfessionalProfile;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    public function testGetFullNameReturnsClientProfileNameWhenClient(): void
    {
        $user = new User();
        $user->setEmail('test@test.com');

        $clientProfile = new ClientProfile();
        $clientProfile->setFullName('Juan Pérez');
        $clientProfile->setUser($user);
        $user->setClientProfile($clientProfile);

        $this->assertSame('Juan Pérez', $user->getFullName());
    }

    public function testGetFullNameReturnsProfessionalProfileNameWhenPro(): void
    {
        $user = new User();
        $user->setEmail('pro@test.com');

        $proProfile = new ProfessionalProfile();
        $proProfile->setFullName('María García');
        $proProfile->setUser($user);
        $user->setProfessionalProfile($proProfile);

        $this->assertSame('María García', $user->getFullName());
    }

    public function testGetFullNamePrefersClientOverProfessional(): void
    {
        $user = new User();
        $user->setEmail('both@test.com');

        $clientProfile = new ClientProfile();
        $clientProfile->setFullName('Cliente');
        $clientProfile->setUser($user);
        $user->setClientProfile($clientProfile);

        $proProfile = new ProfessionalProfile();
        $proProfile->setFullName('Profesional');
        $proProfile->setUser($user);
        $user->setProfessionalProfile($proProfile);

        $this->assertSame('Cliente', $user->getFullName());
    }

    public function testGetFullNameReturnsNullWhenNoProfile(): void
    {
        $user = new User();
        $user->setEmail('noprofile@test.com');

        $this->assertNull($user->getFullName());
    }

    public function testVerifiedEmailDefaultsToFalse(): void
    {
        $user = new User();
        $user->setEmail('test@test.com');

        $this->assertFalse($user->isVerifiedEmail());
    }

    public function testVerifiedEmailCanBeSet(): void
    {
        $user = new User();
        $user->setEmail('test@test.com');
        $user->setVerifiedEmail(true);

        $this->assertTrue($user->isVerifiedEmail());
    }

    public function testVerifiedPhoneDefaultsToFalse(): void
    {
        $user = new User();
        $user->setEmail('test@test.com');

        $this->assertFalse($user->isVerifiedPhone());
    }

    public function testVerifiedPhoneCanBeSet(): void
    {
        $user = new User();
        $user->setEmail('test@test.com');

        $this->assertFalse($user->isVerifiedPhone());
    }
}
