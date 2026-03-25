<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Entity\VerificationToken;
use App\Repository\VerificationTokenRepository;
use App\Service\EmailVerificationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

final class EmailVerificationServiceTest extends TestCase
{
    public function testSendVerificationEmailCreatesTokenAndUsesFrontendUrl(): void
    {
        $user = new User();
        $user->setEmail('user@example.com');

        $tokenRepository = $this->createMock(VerificationTokenRepository::class);
        $tokenRepository
            ->expects($this->once())
            ->method('deleteForUser')
            ->with($user, VerificationToken::TYPE_EMAIL);

        $em = $this->createMock(EntityManagerInterface::class);
        $em
            ->expects($this->once())
            ->method('getRepository')
            ->with(VerificationToken::class)
            ->willReturn($tokenRepository);
        $em
            ->expects($this->once())
            ->method('persist')
            ->willReturnCallback(function ($entity): void {
                if ($entity instanceof VerificationToken) {
                    self::assertSame(VerificationToken::TYPE_EMAIL, $entity->getType());
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
                self::assertStringContainsString('/verify-email?token=', (string) $email->getHtmlBody());
                self::assertStringContainsString('https://frontend.example.com', (string) $email->getHtmlBody());
            });

        $logger = $this->createMock(LoggerInterface::class);

        $service = new EmailVerificationService(
            $em,
            $mailer,
            $logger,
            'https://frontend.example.com'
        );

        $service->sendVerificationEmail($user);
    }
}

