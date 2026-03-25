<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\PasswordResetController;
use App\Service\PasswordResetService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Validation;

final class PasswordResetControllerTest extends TestCase
{
    public function testForgotPasswordWithInvalidEmailReturnsBadRequest(): void
    {
        $service = $this->createMock(PasswordResetService::class);
        $service->expects($this->never())->method('sendResetEmail');

        $validator = Validation::createValidator();
        $controller = new PasswordResetController($service, $validator);

        $request = Request::create(
            '/api/users/forgot-password',
            'POST',
            [],
            [],
            [],
            [],
            json_encode(['email' => 'not-an-email'], JSON_THROW_ON_ERROR)
        );

        $response = $controller->forgotPassword($request);

        $this->assertSame(400, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertSame('Introduce un email válido.', $data['message']);
    }

    public function testForgotPasswordValidEmailReturnsGenericSuccess(): void
    {
        $service = $this->createMock(PasswordResetService::class);
        $service
            ->expects($this->once())
            ->method('sendResetEmail')
            ->with('user@example.com');

        $validator = Validation::createValidator();
        $controller = new PasswordResetController($service, $validator);

        $request = Request::create(
            '/api/users/forgot-password',
            'POST',
            [],
            [],
            [],
            [],
            json_encode(['email' => 'user@example.com'], JSON_THROW_ON_ERROR)
        );

        $response = $controller->forgotPassword($request);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertStringContainsString('Si el email está registrado', $data['message']);
    }

    public function testResetPasswordMissingTokenReturnsBadRequest(): void
    {
        $service = $this->createMock(PasswordResetService::class);
        $validator = Validation::createValidator();
        $controller = new PasswordResetController($service, $validator);

        $request = Request::create(
            '/api/users/reset-password',
            'POST',
            [],
            [],
            [],
            [],
            json_encode(['password' => 'new-password'], JSON_THROW_ON_ERROR)
        );

        $response = $controller->resetPassword($request);

        $this->assertSame(400, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('El token es obligatorio.', $data['message']);
    }

    public function testResetPasswordTooShortReturnsBadRequest(): void
    {
        $service = $this->createMock(PasswordResetService::class);
        $service->expects($this->never())->method('resetPassword');

        $validator = Validation::createValidator();
        $controller = new PasswordResetController($service, $validator);

        $request = Request::create(
            '/api/users/reset-password',
            'POST',
            [],
            [],
            [],
            [],
            json_encode(['token' => 't', 'password' => '123'], JSON_THROW_ON_ERROR)
        );

        $response = $controller->resetPassword($request);

        $this->assertSame(400, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('La contraseña debe tener al menos 6 caracteres.', $data['message']);
    }

    public function testResetPasswordInvalidTokenReturnsBadRequest(): void
    {
        $service = $this->createMock(PasswordResetService::class);
        $service
            ->expects($this->once())
            ->method('resetPassword')
            ->with('bad-token', 'new-password')
            ->willReturn(false);

        $validator = Validation::createValidator();
        $controller = new PasswordResetController($service, $validator);

        $request = Request::create(
            '/api/users/reset-password',
            'POST',
            [],
            [],
            [],
            [],
            json_encode(['token' => 'bad-token', 'password' => 'new-password'], JSON_THROW_ON_ERROR)
        );

        $response = $controller->resetPassword($request);

        $this->assertSame(400, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame(
            'Token inválido o expirado. Solicita un nuevo enlace de recuperación.',
            $data['message']
        );
    }

    public function testResetPasswordValidTokenReturnsSuccess(): void
    {
        $service = $this->createMock(PasswordResetService::class);
        $service
            ->expects($this->once())
            ->method('resetPassword')
            ->with('good-token', 'new-password')
            ->willReturn(true);

        $validator = Validation::createValidator();
        $controller = new PasswordResetController($service, $validator);

        $request = Request::create(
            '/api/users/reset-password',
            'POST',
            [],
            [],
            [],
            [],
            json_encode(['token' => 'good-token', 'password' => 'new-password'], JSON_THROW_ON_ERROR)
        );

        $response = $controller->resetPassword($request);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertStringContainsString('Contraseña restablecida correctamente', $data['message']);
    }
}

