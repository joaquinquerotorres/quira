<?php

declare(strict_types=1);

namespace App\Tests\State;

use App\Entity\ClientProfile;
use App\Entity\ProfessionalProfile;
use App\Entity\User;
use App\Service\PhoneComparisonService;
use App\State\ProfessionalProfileOwnerProcessor;
use ApiPlatform\Validator\Exception\ValidationException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ProfessionalProfileOwnerProcessorTest extends TestCase
{
    private function buildPhoneComparisonService(): PhoneComparisonService
    {
        return new PhoneComparisonService();
    }

    public function testSetsUserAndIsVerifiedOnPost(): void
    {
        $user = new User();
        $user->setEmail('newpro@test.com');
        $user->setRoles(['ROLE_USER']);

        $profile = new ProfessionalProfile();
        $profile->setFullName('Pro Nuevo');
        $profile->setAddress('Calle Test 1');
        $profile->setTierRequested('FREE');

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);
        $logger = $this->createMock(LoggerInterface::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $persistProcessor = $this->createMock(\ApiPlatform\State\ProcessorInterface::class);
        $persistProcessor->method('process')->willReturnCallback(fn($data) => $data);

        $processor = new ProfessionalProfileOwnerProcessor(
            $persistProcessor,
            $logger,
            $security,
            $em,
            new \App\Service\ProfessionalVerificationService(),
            $this->buildPhoneComparisonService()
        );
        $result = $processor->process($profile, new \ApiPlatform\Metadata\Post());

        $this->assertSame($user, $result->getUser());
        $this->assertFalse($result->isVerified());
    }

    public function testValidatesCifAndRecalculatesIsVerifiedForProTier(): void
    {
        $user = new User();
        $user->setEmail('newpro@test.com');
        $user->setRoles(['ROLE_USER']);
        $user->setVerifiedEmail(true);

        $clientProfile = new ClientProfile();
        $clientProfile->setFullName('Cliente');
        $clientProfile->setPhoneNumber('+34 600 111 222');
        $clientProfile->setVerifiedPhone(true);
        $user->setClientProfile($clientProfile);

        $profile = new ProfessionalProfile();
        $profile->setFullName('Pro Nuevo');
        $profile->setAddress('Calle Test 2');
        $profile->setTierRequested('PRO');
        $profile->setPhoneNumber('600111222');
        $profile->setVerifiedPhone(true);
        $profile->setTaxId('A58818501'); // ejemplo válido

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);
        $logger = $this->createMock(LoggerInterface::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $persistProcessor = $this->createMock(\ApiPlatform\State\ProcessorInterface::class);
        $persistProcessor->method('process')->willReturnCallback(fn($data) => $data);

        $processor = new ProfessionalProfileOwnerProcessor(
            $persistProcessor,
            $logger,
            $security,
            $em,
            new \App\Service\ProfessionalVerificationService(),
            $this->buildPhoneComparisonService()
        );
        $result = $processor->process($profile, new \ApiPlatform\Metadata\Post());

        $this->assertTrue($result->isVerifiedTaxId());
        $this->assertTrue($result->isVerified());
    }

    public function testThrowsWhenCifIsInvalid(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('El CIF no es correcto.');

        $user = new User();
        $user->setEmail('pro-invalid@test.com');
        $user->setRoles(['ROLE_USER']);
        $user->setVerifiedEmail(true);

        $profile = new ProfessionalProfile();
        $profile->setFullName('Pro');
        $profile->setAddress('Calle Test 3');
        $profile->setTierRequested('PRO');
        $profile->setVerifiedPhone(true);
        $profile->setTaxId('A58818500'); // control incorrecto

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);
        $logger = $this->createMock(LoggerInterface::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $persistProcessor = $this->createMock(\ApiPlatform\State\ProcessorInterface::class);

        $processor = new ProfessionalProfileOwnerProcessor(
            $persistProcessor,
            $logger,
            $security,
            $em,
            new \App\Service\ProfessionalVerificationService(),
            $this->buildPhoneComparisonService()
        );
        $processor->process($profile, new \ApiPlatform\Metadata\Post());
    }

    public function testThrowsWhenUserAlreadyHasProProfile(): void
    {
        $user = new User();
        $user->setEmail('existing@test.com');
        $existingPro = new ProfessionalProfile();
        $existingPro->setFullName('Existing');
        $existingPro->setAddress('Calle Test 4');
        $existingPro->setUser($user);
        $user->setProfessionalProfile($existingPro);

        $profile = new ProfessionalProfile();
        $profile->setFullName('Segundo intento');
        $profile->setAddress('Calle Test 5');

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);
        $logger = $this->createMock(LoggerInterface::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $persistProcessor = $this->createMock(\ApiPlatform\State\ProcessorInterface::class);

        $processor = new ProfessionalProfileOwnerProcessor(
            $persistProcessor,
            $logger,
            $security,
            $em,
            new \App\Service\ProfessionalVerificationService(),
            $this->buildPhoneComparisonService()
        );

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('Ya tienes un perfil profesional');
        $processor->process($profile, new \ApiPlatform\Metadata\Post());
    }

    public function testThrowsWhenNotLoggedIn(): void
    {
        $profile = new ProfessionalProfile();
        $profile->setFullName('Pro');
        $profile->setAddress('Calle Test 6');

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn(null);
        $logger = $this->createMock(LoggerInterface::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $persistProcessor = $this->createMock(\ApiPlatform\State\ProcessorInterface::class);

        $processor = new ProfessionalProfileOwnerProcessor(
            $persistProcessor,
            $logger,
            $security,
            $em,
            new \App\Service\ProfessionalVerificationService(),
            $this->buildPhoneComparisonService()
        );

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
        $profile->setAddress('Calle Test 7');
        $profile->setTierRequested('PRO');

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);
        $logger = $this->createMock(LoggerInterface::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $persistProcessor = $this->createMock(\ApiPlatform\State\ProcessorInterface::class);
        $persistProcessor->method('process')->willReturnCallback(fn($data) => $data);

        $processor = new ProfessionalProfileOwnerProcessor(
            $persistProcessor,
            $logger,
            $security,
            $em,
            new \App\Service\ProfessionalVerificationService(),
            $this->buildPhoneComparisonService()
        );
        $result = $processor->process($profile, new \ApiPlatform\Metadata\Post());

        $this->assertNotNull($result->getPaidThroughAt());
    }

    public function testAutoverifyIsFalseWhenClientPhoneIsNotVerified(): void
    {
        $user = new User();
        $user->setEmail('pro-phone-invalid@test.com');
        $user->setRoles(['ROLE_USER']);

        $clientProfile = new ClientProfile();
        $clientProfile->setFullName('Cliente');
        $clientProfile->setPhoneNumber('600111222');
        $clientProfile->setVerifiedPhone(false);
        $user->setClientProfile($clientProfile);

        $profile = new ProfessionalProfile();
        $profile->setFullName('Pro');
        $profile->setAddress('Calle Test 8');
        $profile->setPhoneNumber('+34 600 111 222');
        $profile->setVerifiedPhone(true);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);
        $logger = $this->createMock(LoggerInterface::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $persistProcessor = $this->createMock(\ApiPlatform\State\ProcessorInterface::class);
        $persistProcessor->method('process')->willReturnCallback(fn($data) => $data);

        $processor = new ProfessionalProfileOwnerProcessor(
            $persistProcessor,
            $logger,
            $security,
            $em,
            new \App\Service\ProfessionalVerificationService(),
            $this->buildPhoneComparisonService()
        );

        $result = $processor->process($profile, new \ApiPlatform\Metadata\Post());
        $this->assertFalse($result->isVerifiedPhone());
    }

    public function testThrowsValidationWhenAddressIsMissing(): void
    {
        $user = new User();
        $user->setEmail('pro-no-address@test.com');
        $user->setRoles(['ROLE_USER']);

        $profile = new ProfessionalProfile();
        $profile->setFullName('Pro Sin Direccion');
        $profile->setAddress(null);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);
        $logger = $this->createMock(LoggerInterface::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $persistProcessor = $this->createMock(\ApiPlatform\State\ProcessorInterface::class);
        $persistProcessor->method('process')->willReturnCallback(fn($data) => $data);

        $processor = new ProfessionalProfileOwnerProcessor(
            $persistProcessor,
            $logger,
            $security,
            $em,
            new \App\Service\ProfessionalVerificationService(),
            $this->buildPhoneComparisonService()
        );

        $this->expectException(ValidationException::class);
        $processor->process($profile, new \ApiPlatform\Metadata\Post());
    }

    public function testForcesVerifiedPhoneFalseWhenPatchChangesPhoneToDifferentOne(): void
    {
        $user = new User();
        $user->setEmail('pro-patch@test.com');
        $user->setRoles(['ROLE_USER']);

        $clientProfile = new ClientProfile();
        $clientProfile->setFullName('Cliente');
        $clientProfile->setPhoneNumber('600111222');
        $clientProfile->setVerifiedPhone(true);
        $user->setClientProfile($clientProfile);

        $previous = new ProfessionalProfile();
        $previous->setFullName('Pro');
        $previous->setAddress('Calle Test 9');
        $previous->setPhoneNumber('600111222');
        $previous->setVerifiedPhone(true);
        $previous->setUser($user);

        $current = new ProfessionalProfile();
        $current->setFullName('Pro');
        $current->setAddress('Calle Test 10');
        $current->setPhoneNumber('699999999');
        $current->setVerifiedPhone(false);
        $current->setUser($user);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);
        $logger = $this->createMock(LoggerInterface::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $persistProcessor = $this->createMock(\ApiPlatform\State\ProcessorInterface::class);
        $persistProcessor->method('process')->willReturnCallback(fn($data) => $data);

        $processor = new ProfessionalProfileOwnerProcessor(
            $persistProcessor,
            $logger,
            $security,
            $em,
            new \App\Service\ProfessionalVerificationService(),
            $this->buildPhoneComparisonService()
        );

        $result = $processor->process($current, new \ApiPlatform\Metadata\Patch(), [], ['previous_data' => $previous]);
        $this->assertFalse($result->isVerifiedPhone());
    }

    public function testAutoverifyIsFalseWhenPhoneDoesNotMatchClientPhone(): void
    {
        $user = new User();
        $user->setEmail('pro-phone-mismatch@test.com');
        $user->setRoles(['ROLE_USER']);

        $clientProfile = new ClientProfile();
        $clientProfile->setFullName('Cliente');
        $clientProfile->setPhoneNumber('600111222');
        $clientProfile->setVerifiedPhone(true);
        $user->setClientProfile($clientProfile);

        $profile = new ProfessionalProfile();
        $profile->setFullName('Pro');
        $profile->setAddress('Calle Test 11');
        $profile->setPhoneNumber('677777777');
        $profile->setVerifiedPhone(true);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);
        $logger = $this->createMock(LoggerInterface::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $persistProcessor = $this->createMock(\ApiPlatform\State\ProcessorInterface::class);
        $persistProcessor->method('process')->willReturnCallback(fn($data) => $data);

        $processor = new ProfessionalProfileOwnerProcessor(
            $persistProcessor,
            $logger,
            $security,
            $em,
            new \App\Service\ProfessionalVerificationService(),
            $this->buildPhoneComparisonService()
        );

        $result = $processor->process($profile, new \ApiPlatform\Metadata\Post());
        $this->assertFalse($result->isVerifiedPhone());
    }
}
