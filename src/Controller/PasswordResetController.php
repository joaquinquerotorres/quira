<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\PasswordResetService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/users')]
class PasswordResetController extends AbstractController
{
    public function __construct(
        private readonly PasswordResetService $passwordResetService,
        private readonly ValidatorInterface $validator
    ) {
    }

    #[Route('/forgot-password', name: 'api_users_forgot_password', methods: ['POST'])]
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $email = trim((string) ($data['email'] ?? ''));

        $errors = $this->validator->validate($email, [
            new Assert\NotBlank(message: 'El email es obligatorio.'),
            new Assert\Email(message: 'Introduce un email válido.'),
        ]);

        if ($errors->count() > 0) {
            return new JsonResponse([
                'success' => false,
                'message' => $errors->get(0)->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->passwordResetService->sendResetEmail($email);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error al enviar el correo. Inténtalo más tarde.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse([
            'success' => true,
            'message' => 'Si el email está registrado, recibirás un enlace para restablecer tu contraseña.',
        ]);
    }

    #[Route('/reset-password', name: 'api_users_reset_password', methods: ['POST'])]
    public function resetPassword(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $token = trim((string) ($data['token'] ?? ''));
        $password = $data['password'] ?? $data['newPassword'] ?? '';

        if (empty($token)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'El token es obligatorio.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $errors = $this->validator->validate($password, [
            new Assert\NotBlank(message: 'La contraseña es obligatoria.'),
            new Assert\Length(min: 6, minMessage: 'La contraseña debe tener al menos 6 caracteres.'),
        ]);

        if ($errors->count() > 0) {
            return new JsonResponse([
                'success' => false,
                'message' => $errors->get(0)->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->passwordResetService->resetPassword($token, $password)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Token inválido o expirado. Solicita un nuevo enlace de recuperación.',
            ], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse([
            'success' => true,
            'message' => 'Contraseña restablecida correctamente. Ya puedes iniciar sesión.',
        ]);
    }
}
