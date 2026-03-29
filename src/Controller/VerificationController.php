<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\VerificationTokenRepository;
use App\Service\EmailVerificationService;
use App\Service\PhoneVerificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/verify')]
class VerificationController extends AbstractController
{
    public function __construct(
        private readonly VerificationTokenRepository $tokenRepository,
        private readonly EntityManagerInterface $em,
        private readonly EmailVerificationService $emailVerificationService,
        private readonly PhoneVerificationService $phoneVerificationService,
        private readonly LoggerInterface $logger
    ) {
    }

    #[Route('/email', name: 'api_verify_email', methods: ['GET', 'POST'])]
    public function confirmEmail(Request $request): JsonResponse
    {
        $token = $request->query->get('token')
            ?? $request->request->get('token')
            ?? (json_decode($request->getContent(), true) ?? [])['token'] ?? null;
        if (empty($token)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Token de verificación requerido.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $verificationToken = $this->tokenRepository->findValidByToken($token, 'email');
        if (!$verificationToken) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Token inválido o expirado. Solicita un nuevo correo de verificación.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $user = $verificationToken->getUser();
        $user->setVerifiedEmail(true);
        $this->em->remove($verificationToken);
        $this->em->flush();

        return new JsonResponse([
            'success' => true,
            'message' => 'Email verificado correctamente. Ya puedes iniciar sesión.',
        ]);
    }

    #[Route('/email/resend', name: 'api_verify_email_resend', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function resendEmailVerification(): JsonResponse
    {
        $user = $this->getUser();
        if ($user->isVerifiedEmail()) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Tu email ya está verificado.',
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->emailVerificationService->sendVerificationEmail($user);
            return new JsonResponse([
                'success' => true,
                'message' => 'Se ha enviado un nuevo correo de verificación.',
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error al enviar el correo. Inténtalo más tarde.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/phone/send', name: 'api_verify_phone_send', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function sendPhoneOtp(Request $request): JsonResponse
    {
        $user = $this->getUser();
        $data = json_decode($request->getContent(), true) ?? [];
        $profile = $data['profile'] ?? 'client';

        $phone = match ($profile) {
            'professional' => $user->getProfessionalProfile()?->getPhoneNumber(),
            default => $user->getClientProfile()?->getPhoneNumber(),
        };

        if (empty($phone)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Añade un número de teléfono en tu perfil antes de solicitar el código.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $normalizedPhone = $this->phoneVerificationService->normalizePhone($phone);

        // Si el otro perfil ya tiene este mismo número verificado, no enviamos un segundo SMS.
        $clientProfile = $user->getClientProfile();
        $proProfile = $user->getProfessionalProfile();
        $otherProfilePhone = match ($profile) {
            'professional' => $clientProfile?->getPhoneNumber(),
            default => $proProfile?->getPhoneNumber(),
        };
        $otherProfileVerified = match ($profile) {
            'professional' => $clientProfile?->isVerifiedPhone() ?? false,
            default => $proProfile?->isVerifiedPhone() ?? false,
        };

        if ($otherProfilePhone !== null && $otherProfilePhone !== '' && $otherProfileVerified) {
            $normalizedOther = $this->phoneVerificationService->normalizePhone($otherProfilePhone);
            if ($normalizedOther === $normalizedPhone) {
                return new JsonResponse([
                    'success' => true,
                    'skipped' => true,
                    'reason' => 'same_number_already_verified',
                    'message' => 'Teléfono ya verificado.',
                ]);
            }
        }

        try {
            $this->phoneVerificationService->sendOtp($phone, $user);
            return new JsonResponse([
                'success' => true,
                'message' => 'Código enviado por SMS.',
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('SMS OTP fallido', [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
            return new JsonResponse([
                'success' => false,
                'message' => 'Error al enviar el SMS. Verifica el número e inténtalo de nuevo.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/phone/confirm', name: 'api_verify_phone_confirm', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function confirmPhone(Request $request): JsonResponse
    {
        $user = $this->getUser();
        $data = json_decode($request->getContent(), true) ?? [];
        $code = trim((string) ($data['code'] ?? ''));
        $profile = $data['profile'] ?? 'client';

        $phone = match ($profile) {
            'professional' => $user->getProfessionalProfile()?->getPhoneNumber(),
            default => $user->getClientProfile()?->getPhoneNumber(),
        };

        if (empty($code)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Código requerido.',
            ], Response::HTTP_BAD_REQUEST);
        }

        if (empty($phone)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'No tienes teléfono en tu perfil.',
            ], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->phoneVerificationService->verifyOtp($phone, $code, $user)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Código incorrecto o expirado.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $normalizedPhone = $this->phoneVerificationService->normalizePhone($phone);

        $clientProfile = $user->getClientProfile();
        if ($clientProfile !== null && $clientProfile->getPhoneNumber() !== null) {
            $normalizedClient = $this->phoneVerificationService->normalizePhone($clientProfile->getPhoneNumber());
            if ($normalizedClient === $normalizedPhone) {
                $clientProfile->setVerifiedPhone(true);
            }
        }

        $proProfile = $user->getProfessionalProfile();
        if ($proProfile !== null && $proProfile->getPhoneNumber() !== null) {
            $normalizedPro = $this->phoneVerificationService->normalizePhone($proProfile->getPhoneNumber());
            if ($normalizedPro === $normalizedPhone) {
                $proProfile->setVerifiedPhone(true);
            }
        }

        $this->em->flush();

        return new JsonResponse([
            'success' => true,
            'message' => 'Teléfono verificado correctamente.',
        ]);
    }
}
