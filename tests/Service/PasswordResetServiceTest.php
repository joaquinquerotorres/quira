<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Entity\VerificationToken;
use App\Repository\UserRepository;
use App\Repository\VerificationTokenRepository;
use App\Service\PasswordResetService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PasswordResetServiceTest extends TestCase
{
    public function testSendResetEmailReturnsSilentlyWhenUserNotFound(): void
    {
        $userRepository = $this->createMock(UserRepository::class);
        $userRepository
            ->expects($this->once())
            ->method('findOneBy')
            ->with(['email' => 'missing@example.com'])
            ->willReturn(null);

        $tokenRepository = $this->createMock(VerificationTokenRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $mailer = $this->createMock(MailerInterface::class);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $tokenRepository->expects($this->never())->method('deleteForUser');
        $em->expects($this->never())->method('persist');
        $em->expects($this->never())->method('flush');
        $mailer->expects($this->never())->method('send');

        $service = new PasswordResetService(
            $userRepository,
            $tokenRepository,
            $em,
            $mailer,
            $hasher,
            $logger,
            'http://frontend.test'
        );

        $service->sendResetEmail('missing@example.com');

        // No excepciones y sin llamadas a persist/send: test pasa.
        $this->assertTrue(true);
    }

    public function testSendResetEmailCreatesTokenAndSendsEmail(): void
    {
        $user = new User();
        $user->setEmail('user@example.com');

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository
            ->method('findOneBy')
            ->with(['email' => 'user@example.com'])
            ->willReturn($user);

        $tokenRepository = $this->createMock(VerificationTokenRepository::class);
        $tokenRepository
            ->expects($this->once())
            ->method('deleteForUser')
            ->with($user, VerificationToken::TYPE_PASSWORD_RESET);

        $em = $this->createMock(EntityManagerInterface::class);
        $em
            ->expects($this->once())
            ->method('persist')
            ->willReturnCallback(function ($entity): void {
                if ($entity instanceof VerificationToken) {
                    self::assertSame(VerificationToken::TYPE_PASSWORD_RESET, $entity->getType());
                    self::assertFalse($entity->isExpired());
                } else {
                    self::fail('Expected a VerificationToken instance');
                }
            });
        $em->expects($this->once())->method('flush');

        $mailer = $this->createMock(MailerInterface::class);
        $mailer
            ->expects($this->once())
            ->method('send')
            ->willReturnCallback(function (Email $email): void {
                self::assertSame('user@example.com', $email->getTo()[0]->getAddress());
                self::assertStringContainsString('Recupera tu contraseña', (string) $email->getSubject());
                self::assertStringContainsString('/reset-password?token=', (string) $email->getHtmlBody());
            });

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $service = new PasswordResetService(
            $userRepository,
            $tokenRepository,
            $em,
            $mailer,
            $hasher,
            $logger,
            'http://frontend.test'
        );

        $service->sendResetEmail('user@example.com');
    }

    public function testResetPasswordReturnsFalseWhenTokenInvalid(): void
    {
        $tokenRepository = $this->createMock(VerificationTokenRepository::class);
        $tokenRepository
            ->method('findValidByToken')
            ->with('bad-token', VerificationToken::TYPE_PASSWORD_RESET)
            ->willReturn(null);

        $userRepository = $this->createMock(UserRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $mailer = $this->createMock(MailerInterface::class);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $em->expects($this->never())->method('remove');
        $em->expects($this->never())->method('flush');
        $hasher->expects($this->never())->method('hashPassword');

        $service = new PasswordResetService(
            $userRepository,
            $tokenRepository,
            $em,
            $mailer,
            $hasher,
            $logger,
            'http://frontend.test'
        );

        $this->assertFalse($service->resetPassword('bad-token', 'new-password'));
    }

    public function testResetPasswordUpdatesUserPasswordAndDeletesToken(): void
    {
        $user = new User();
        $user->setEmail('user@example.com');

        $token = new VerificationToken();
        $token->setUser($user);
        $token->setType(VerificationToken::TYPE_PASSWORD_RESET);
        $token->setToken('good-token');

        $tokenRepository = $this->createMock(VerificationTokenRepository::class);
        $tokenRepository
            ->method('findValidByToken')
            ->with('good-token', VerificationToken::TYPE_PASSWORD_RESET)
            ->willReturn($token);

        $userRepository = $this->createMock(UserRepository::class);

        $em = $this->createMock(EntityManagerInterface::class);
        $em
            ->expects($this->once())
            ->method('remove')
            ->with($token);
        $em->expects($this->once())->method('flush');

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher
            ->expects($this->once())
            ->method('hashPassword')
            ->with($user, 'new-password')
            ->willReturn('hashed-password');

        $mailer = $this->createMock(MailerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $service = new PasswordResetService(
            $userRepository,
            $tokenRepository,
            $em,
            $mailer,
            $hasher,
            $logger,
            'http://frontend.test'
        );

        $this->assertTrue($service->resetPassword('good-token', 'new-password'));
        $this->assertSame('hashed-password', $user->getPassword());
    }
}

