<?php

namespace App\Service;

use Kreait\Firebase\Contract\Auth;
use Kreait\Firebase\Exception\Auth\FailedToVerifyToken;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class SocialAuthService
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private Auth $firebaseAuth
    ) {}

    public function verifyFirebaseToken(string $token): array
    {
        try {
            $verifiedIdToken = $this->firebaseAuth->verifyIdToken($token);
            $claims = $verifiedIdToken->claims();

            return [
                'uid' => $claims->get('sub'), 
                'email' => $claims->get('email'),
                'name' => $claims->get('name') ?? 'Usuario',
                'avatar' => $claims->get('picture') ?? null,
            ];
        } catch (FailedToVerifyToken $e) {
            $this->logger->error("❌ Error al verificar el token de Firebase: " . $e->getMessage());
            throw new BadRequestHttpException('Token de Firebase inválido: ' . $e->getMessage());
        }
    }
}