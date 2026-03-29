<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Enum\NotificationAudience;
use App\Mail\EmailBranding;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Kreait\Firebase\Contract\Messaging;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

final class NotificationServiceTest extends TestCase
{
    public function testClientAudienceUsesNotificationsClientNotFreeTier(): void
    {
        $user = $this->hybridFreeProfessionalUser();
        $user->setFcmToken('fcm-hybrid');

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $messaging = $this->createMock(Messaging::class);
        $messaging->expects(self::once())->method('send')->willReturn([]);

        $service = $this->createService($mailer, $messaging, free: 'EMAIL', client: 'PUSH');

        $service->send(
            $user,
            'T',
            'M',
            'TEST',
            NotificationAudience::Client,
            1
        );
    }

    public function testProfessionalAudienceWithRoleFreeUsesNotificationsFree(): void
    {
        $user = $this->hybridFreeProfessionalUser();
        $user->setFcmToken('fcm-hybrid');

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send')->with(self::callback(function (Email $email): bool {
            return $email->getTo()[0]->getAddress() === 'hybrid@example.com';
        }));

        $messaging = $this->createMock(Messaging::class);
        $messaging->expects(self::never())->method('send');

        $service = $this->createService($mailer, $messaging, free: 'EMAIL', client: 'PUSH');

        $service->send(
            $user,
            'T',
            'M',
            'TEST',
            NotificationAudience::Professional,
            1
        );
    }

    public function testProfessionalAudienceWithRoleProUsesNotificationsPro(): void
    {
        $user = new User();
        $user->setEmail('pro@example.com');
        $user->setPassword('x');
        $user->setRoles(['ROLE_USER', 'ROLE_PROFESSIONAL', 'ROLE_PRO']);
        $user->setFcmToken('fcm-pro');

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $messaging = $this->createMock(Messaging::class);
        $messaging->expects(self::once())->method('send')->willReturn([]);

        $service = $this->createService($mailer, $messaging, pro: 'PUSH');

        $service->send(
            $user,
            'T',
            'M',
            'TEST',
            NotificationAudience::Professional,
            null
        );
    }

    public function testClientAudienceEmailChannelSendsMailNotPushWhenMailSucceeds(): void
    {
        $user = $this->hybridFreeProfessionalUser();
        $user->setFcmToken('fcm-hybrid');

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send');

        $messaging = $this->createMock(Messaging::class);
        $messaging->expects(self::never())->method('send');

        $service = $this->createService($mailer, $messaging, client: 'EMAIL');

        $service->send(
            $user,
            'T',
            'M',
            'TEST',
            NotificationAudience::Client,
            1
        );
    }

    public function testPushChannelFallsBackToEmailWhenNoFcmToken(): void
    {
        $user = new User();
        $user->setEmail('only@example.com');
        $user->setPassword('x');
        $user->setRoles(['ROLE_USER', 'ROLE_PROFESSIONAL', 'ROLE_PRO']);
        $user->setFcmToken(null);

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send');

        $messaging = $this->createMock(Messaging::class);
        $messaging->expects(self::never())->method('send');

        $service = $this->createService($mailer, $messaging, pro: 'PUSH');

        $service->send(
            $user,
            'T',
            'M',
            'TEST',
            NotificationAudience::Professional,
            null
        );
    }

    private function hybridFreeProfessionalUser(): User
    {
        $user = new User();
        $user->setEmail('hybrid@example.com');
        $user->setPassword('x');
        $user->setRoles(['ROLE_USER', 'ROLE_PROFESSIONAL', 'ROLE_FREE']);

        return $user;
    }

    private function createService(
        MailerInterface $mailer,
        Messaging $messaging,
        string $pro = 'PUSH',
        string $solver = 'PUSH',
        string $free = 'EMAIL',
        string $client = 'PUSH',
    ): NotificationService {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::atLeastOnce())->method('persist');
        $em->expects(self::atLeastOnce())->method('flush');

        return new NotificationService(
            $em,
            $mailer,
            $messaging,
            new NullLogger(),
            new EmailBranding('https://cdn.example.com/logo.png'),
            '',
            '',
            '',
            $pro,
            $solver,
            $free,
            $client,
        );
    }
}
