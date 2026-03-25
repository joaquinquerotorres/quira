<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\SocialLoginController;
use App\Entity\User;
use App\Service\SocialAuthService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final class SocialLoginControllerTest extends TestCase
{
    public function testNewUserCreatedViaSocialLoginHasVerifiedEmailTrue(): void
    {
        $firebaseUser = [
            'email' => 'newuser@gmail.com',
            'name' => 'Test User',
            'avatar' => 'https://example.com/avatar.jpg',
        ];

        $socialAuth = $this->createMock(SocialAuthService::class);
        $socialAuth->method('verifyFirebaseToken')
            ->with('valid-token')
            ->willReturn($firebaseUser);

        $userRepository = $this->createMock(EntityRepository::class);
        $userRepository->method('findOneBy')->willReturn(null);

        $persistedUser = null;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(User::class)->willReturn($userRepository);
        $em->method('persist')->willReturnCallback(function ($entity) use (&$persistedUser) {
            if ($entity instanceof User) {
                $persistedUser = $entity;
            }
        });
        $em->method('flush')->willReturnCallback(static function (): void {});

        $jwtManager = $this->createMock(JWTTokenManagerInterface::class);
        $jwtManager->method('create')->willReturn('jwt-token');

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturn('hashed');

        $normalizer = $this->createMock(NormalizerInterface::class);
        $normalizer->method('normalize')->willReturn(['email' => 'newuser@gmail.com']);

        $logger = $this->createMock(LoggerInterface::class);

        $controller = new SocialLoginController(
            $logger,
            $em,
            $socialAuth,
            $jwtManager,
            $hasher,
            $normalizer
        );

        $request = Request::create('/api/social/login', 'POST', [], [], [], [], json_encode(['token' => 'valid-token']));
        $response = $controller->login($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertInstanceOf(User::class, $persistedUser);
        $this->assertTrue($persistedUser->isVerifiedEmail());
    }

    public function testExistingUserLoginReturnsSuccess(): void
    {
        $firebaseUser = [
            'email' => 'existing@test.com',
            'name' => 'Existing User',
        ];

        $existingUser = new User();
        $existingUser->setEmail('existing@test.com');
        $existingUser->setVerifiedEmail(true);

        $socialAuth = $this->createMock(SocialAuthService::class);
        $socialAuth->method('verifyFirebaseToken')->willReturn($firebaseUser);

        $userRepository = $this->createMock(EntityRepository::class);
        $userRepository->method('findOneBy')->willReturn($existingUser);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(User::class)->willReturn($userRepository);
        $em->expects($this->never())->method('persist');
        $em->expects($this->never())->method('flush');

        $jwtManager = $this->createMock(JWTTokenManagerInterface::class);
        $jwtManager->method('create')->willReturn('jwt');

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $normalizer = $this->createMock(NormalizerInterface::class);
        $normalizer->method('normalize')->willReturn([]);

        $logger = $this->createMock(LoggerInterface::class);

        $controller = new SocialLoginController(
            $logger,
            $em,
            $socialAuth,
            $jwtManager,
            $hasher,
            $normalizer
        );

        $request = Request::create('/api/social/login', 'POST', [], [], [], [], json_encode(['token' => 't']));
        $response = $controller->login($request);

        $this->assertSame(200, $response->getStatusCode());
    }
}
