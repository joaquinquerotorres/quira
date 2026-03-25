<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;

final class LoginSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    public function __construct(
        private readonly AuthenticationSuccessHandlerInterface $jwtSuccessHandler
    ) {
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): ?Response
    {
        $user = $token->getUser();

        if ($user instanceof User && !$user->isVerifiedEmail()) {
            return new JsonResponse([
                'code' => 403,
                'message' => 'Debes verificar tu correo electrónico antes de poder iniciar sesión. Revisa tu bandeja de entrada.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $this->jwtSuccessHandler->onAuthenticationSuccess($request, $token);
    }
}
