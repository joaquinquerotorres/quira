<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use App\Security\LoginSuccessHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;

final class LoginSuccessHandlerTest extends TestCase
{
    public function testReturns403WhenUserEmailNotVerified(): void
    {
        $user = new User();
        $user->setEmail('unverified@test.com');
        $user->setVerifiedEmail(false);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $jwtHandler = $this->createMock(AuthenticationSuccessHandlerInterface::class);
        $jwtHandler->expects($this->never())->method('onAuthenticationSuccess');

        $handler = new LoginSuccessHandler($jwtHandler);
        $request = Request::create('/api/login_check', 'POST');

        $response = $handler->onAuthenticationSuccess($request, $token);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        $this->assertStringContainsString('verificar tu correo', (string) $response->getContent());
    }

    public function testDelegatesToJwtHandlerWhenEmailVerified(): void
    {
        $user = new User();
        $user->setEmail('verified@test.com');
        $user->setVerifiedEmail(true);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $expectedResponse = new Response('{"token":"abc123"}', 200);
        $jwtHandler = $this->createMock(AuthenticationSuccessHandlerInterface::class);
        $jwtHandler->method('onAuthenticationSuccess')
            ->with($this->anything(), $token)
            ->willReturn($expectedResponse);

        $handler = new LoginSuccessHandler($jwtHandler);
        $request = Request::create('/api/login_check', 'POST');

        $response = $handler->onAuthenticationSuccess($request, $token);

        $this->assertSame($expectedResponse, $response);
    }

    public function testDelegatesToJwtHandlerWhenTokenUserIsNotAppUser(): void
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn(new \Symfony\Component\Security\Core\User\InMemoryUser('other@test.com', 'pass'));

        $expectedResponse = new Response('{"token":"xyz"}', 200);
        $jwtHandler = $this->createMock(AuthenticationSuccessHandlerInterface::class);
        $jwtHandler->method('onAuthenticationSuccess')->willReturn($expectedResponse);

        $handler = new LoginSuccessHandler($jwtHandler);
        $request = Request::create('/api/login_check', 'POST');

        $response = $handler->onAuthenticationSuccess($request, $token);

        $this->assertSame($expectedResponse, $response);
    }
}
