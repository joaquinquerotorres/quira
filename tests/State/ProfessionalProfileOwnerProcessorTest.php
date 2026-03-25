<?php

declare(strict_types=1);

namespace App\Tests\State;

use App\Entity\ProfessionalProfile;
use App\Entity\User;
use App\State\ProfessionalProfileOwnerProcessor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ProfessionalProfileOwnerProcessorTest extends TestCase
{
    public function testSetsUserAndIsVerifiedOnPost(): void
    {
        $user = new User();
        $user->setEmail('newpro@test.com');
        $user->setRoles(['ROLE_USER']);

        $profile = new ProfessionalProfile();
        $profile->setFullName('Pro Nuevo');
        $profile->setTierRequested('FREE');

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);
        $logger = $this->createMock(LoggerInterface::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $persistProcessor = $this->createMock(\ApiPlatform\State\ProcessorInterface::class);
        $persistProcessor->method('process')->willReturnCallback(fn($data) => $data);

        $processor = new ProfessionalProfileOwnerProcessor($persistProcessor, $logger, $security, $em);
        $result = $processor->process($profile, new \ApiPlatform\Metadata\Post());

        $this->assertSame($user, $result->getUser());
        $this->assertFalse($result->isVerified());
    }

    public function testThrowsWhenUserAlreadyHasProProfile(): void
    {
        $user = new User();
        $user->setEmail('existing@test.com');
        $existingPro = new ProfessionalProfile();
        $existingPro->setFullName('Existing');
        $existingPro->setUser($user);
        $user->setProfessionalProfile($existingPro);

        $profile = new ProfessionalProfile();
        $profile->setFullName('Segundo intento');

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);
        $logger = $this->createMock(LoggerInterface::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $persistProcessor = $this->createMock(\ApiPlatform\State\ProcessorInterface::class);

        $processor = new ProfessionalProfileOwnerProcessor($persistProcessor, $logger, $security, $em);

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('Ya tienes un perfil profesional');
        $processor->process($profile, new \ApiPlatform\Metadata\Post());
    }

    public function testThrowsWhenNotLoggedIn(): void
    {
        $profile = new ProfessionalProfile();
        $profile->setFullName('Pro');

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn(null);
        $logger = $this->createMock(LoggerInterface::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $persistProcessor = $this->createMock(\ApiPlatform\State\ProcessorInterface::class);

        $processor = new ProfessionalProfileOwnerProcessor($persistProcessor, $logger, $security, $em);

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('Debes estar logueado');
        $processor->process($profile, new \ApiPlatform\Metadata\Post());
    }

    public function testSetsPaidThroughAtForProTier(): void
    {
        $user = new User();
        $user->setEmail('pro@test.com');
        $user->setRoles(['ROLE_USER']);

        $profile = new ProfessionalProfile();
        $profile->setFullName('Pro');
        $profile->setTierRequested('PRO');

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);
        $logger = $this->createMock(LoggerInterface::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $persistProcessor = $this->createMock(\ApiPlatform\State\ProcessorInterface::class);
        $persistProcessor->method('process')->willReturnCallback(fn($data) => $data);

        $processor = new ProfessionalProfileOwnerProcessor($persistProcessor, $logger, $security, $em);
        $result = $processor->process($profile, new \ApiPlatform\Metadata\Post());

        $this->assertNotNull($result->getPaidThroughAt());
    }
}
