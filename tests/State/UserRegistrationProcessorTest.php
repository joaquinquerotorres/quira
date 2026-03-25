<?php

declare(strict_types=1);

namespace App\Tests\State;

use App\Entity\ClientProfile;
use App\Entity\User;
use App\Service\EmailVerificationService;
use App\State\UserRegistrationProcessor;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UserRegistrationProcessorTest extends TestCase
{
    public function testHashesPasswordAndCreatesClientProfile(): void
    {
        $user = new User();
        $user->setEmail('new@test.com');
        $user->setPassword('plainpassword');

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')
            ->willReturn('hashed_password');

        $persistProcessor = $this->createMock(\ApiPlatform\State\ProcessorInterface::class);
        $persistProcessor->method('process')->willReturnCallback(fn($data) => $data);

        $emailVerification = $this->createMock(EmailVerificationService::class);
        $logger = $this->createMock(LoggerInterface::class);
        $em = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);

        $processor = new UserRegistrationProcessor($persistProcessor, $hasher, $emailVerification, $logger, $em);
        $result = $processor->process($user, new \ApiPlatform\Metadata\Post());

        $this->assertSame('hashed_password', $result->getPassword());
        $this->assertInstanceOf(ClientProfile::class, $result->getClientProfile());
        $this->assertSame($result, $result->getClientProfile()->getUser());
    }

    public function testKeepsExistingClientProfile(): void
    {
        $user = new User();
        $user->setEmail('withprofile@test.com');
        $user->setPassword('pass');
        $existing = new ClientProfile();
        $existing->setFullName('Ya existe');
        $user->setClientProfile($existing);

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturn('hashed');

        $persistProcessor = $this->createMock(\ApiPlatform\State\ProcessorInterface::class);
        $persistProcessor->method('process')->willReturnCallback(fn($data) => $data);

        $emailVerification = $this->createMock(EmailVerificationService::class);
        $logger = $this->createMock(LoggerInterface::class);
        $em = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);

        $processor = new UserRegistrationProcessor($persistProcessor, $hasher, $emailVerification, $logger, $em);
        $result = $processor->process($user, new \ApiPlatform\Metadata\Post());

        $this->assertSame($existing, $result->getClientProfile());
    }

    public function testVerifiedEmailStaysFalseForManualRegistration(): void
    {
        $user = new User();
        $user->setEmail('manual@test.com');
        $user->setPassword('plainpassword');

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturn('hashed');

        $persistProcessor = $this->createMock(\ApiPlatform\State\ProcessorInterface::class);
        $persistProcessor->method('process')->willReturnCallback(fn($data) => $data);

        $emailVerification = $this->createMock(EmailVerificationService::class);
        $logger = $this->createMock(LoggerInterface::class);
        $em = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);

        $processor = new UserRegistrationProcessor($persistProcessor, $hasher, $emailVerification, $logger, $em);
        $result = $processor->process($user, new \ApiPlatform\Metadata\Post());

        $this->assertFalse($result->isVerifiedEmail());
        $this->assertFalse($result->isVerifiedPhone());
    }
}
