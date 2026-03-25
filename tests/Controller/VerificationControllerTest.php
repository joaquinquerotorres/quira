<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\VerificationController;
use App\Entity\User;
use App\Entity\VerificationToken;
use App\Repository\VerificationTokenRepository;
use App\Service\PhoneVerificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class VerificationControllerTest extends TestCase
{
    public function testConfirmEmailReadsTokenFromJsonBody(): void
    {
        $user = new User();
        $user->setEmail('user@example.com');

        $token = new VerificationToken();
        $token->setUser($user);
        $token->setType(VerificationToken::TYPE_EMAIL);
        $token->setToken('json-token');

        $repo = $this->createMock(VerificationTokenRepository::class);
        $repo
            ->expects($this->once())
            ->method('findValidByToken')
            ->with('json-token', VerificationToken::TYPE_EMAIL)
            ->willReturn($token);

        $em = $this->createMock(EntityManagerInterface::class);
        $em
            ->expects($this->once())
            ->method('remove')
            ->with($token);
        $em->expects($this->once())->method('flush');

        $emailService = $this->getMockBuilder('App\Service\EmailVerificationService')->disableOriginalConstructor()->getMock();
        $phoneService = new PhoneVerificationService(
            'sid',
            'token',
            '+10000000000',
            $this->createMock(CacheInterface::class),
            $this->createMock(LoggerInterface::class)
        );

        $logger = $this->createMock(LoggerInterface::class);
        $controller = new VerificationController($repo, $em, $emailService, $phoneService, $logger);

        $request = Request::create(
            '/api/verify/email',
            'POST',
            [],
            [],
            [],
            [],
            json_encode(['token' => 'json-token'], JSON_THROW_ON_ERROR)
        );

        $response = $controller->confirmEmail($request);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertTrue($user->isVerifiedEmail());
    }

    public function testConfirmEmailWithoutTokenReturnsBadRequest(): void
    {
        $repo = $this->createMock(VerificationTokenRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $emailService = $this->getMockBuilder('App\Service\EmailVerificationService')->disableOriginalConstructor()->getMock();
        $phoneService = new PhoneVerificationService(
            'sid',
            'token',
            '+10000000000',
            $this->createMock(CacheInterface::class),
            $this->createMock(LoggerInterface::class)
        );

        $logger = $this->createMock(LoggerInterface::class);
        $controller = new VerificationController($repo, $em, $emailService, $phoneService, $logger);

        $request = Request::create('/api/verify/email', 'POST');
        $response = $controller->confirmEmail($request);

        $this->assertSame(400, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('Token de verificación requerido.', $data['message']);
    }

    public function testConfirmEmailWithInvalidTokenReturnsBadRequest(): void
    {
        $repo = $this->createMock(VerificationTokenRepository::class);
        $repo
            ->expects($this->once())
            ->method('findValidByToken')
            ->with('invalid', VerificationToken::TYPE_EMAIL)
            ->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $emailService = $this->getMockBuilder('App\Service\EmailVerificationService')->disableOriginalConstructor()->getMock();
        $phoneService = new PhoneVerificationService(
            'sid',
            'token',
            '+10000000000',
            $this->createMock(CacheInterface::class),
            $this->createMock(LoggerInterface::class)
        );

        $logger = $this->createMock(LoggerInterface::class);
        $controller = new VerificationController($repo, $em, $emailService, $phoneService, $logger);

        $request = Request::create(
            '/api/verify/email',
            'POST',
            [],
            [],
            [],
            [],
            json_encode(['token' => 'invalid'], JSON_THROW_ON_ERROR)
        );

        $response = $controller->confirmEmail($request);

        $this->assertSame(400, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame(
            'Token inválido o expirado. Solicita un nuevo correo de verificación.',
            $data['message']
        );
    }
}

