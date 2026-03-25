<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\ClientProfile;
use App\Service\SocialAuthService;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

#[Route('/api/social')]
class SocialLoginController extends AbstractController
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly EntityManagerInterface $em,
        private SocialAuthService $socialAuthService,
        private JWTTokenManagerInterface $jwtManager,
        private UserPasswordHasherInterface $passwordHasher,
        private NormalizerInterface $normalizer
    ) {}

    #[Route('/login', name: 'api_social_login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $token = $data['token'] ?? null; 

        if (!$token) {
            $this->logger->warning("❌ No se ha proporcionado un token de autenticación social.");
            return new JsonResponse(['error' => ' El token es obligatorio'], 400);
        }

        try {
            $firebaseUser = $this->socialAuthService->verifyFirebaseToken($token);
        } catch (\Exception $e) {
            $this->logger->error("❌ Error al verificar el token de autenticación social: " . $e->getMessage());
            return new JsonResponse(['error' => $e->getMessage()], 401);
        }

        $email = $firebaseUser['email'];
        $userRepository = $this->em->getRepository(User::class);
    
        $user = $userRepository->findOneBy(['email' => $email]);

        if (!$user) {
            $user = new User();
            $user->setEmail($email);
            $user->setRoles(['ROLE_USER']);
            $user->setVerifiedEmail(true); // Google/Apple verifica el email

            $randomPass = bin2hex(random_bytes(16));
            $user->setPassword($this->passwordHasher->hashPassword($user, $randomPass));

            $clientProfile = new ClientProfile();
            $clientProfile->setFullName($firebaseUser['name']);
            $clientProfile->setAvatar($firebaseUser['avatar']);
            $user->setClientProfile($clientProfile);
            $clientProfile->setUser($user);

            $this->em->persist($clientProfile);
            $this->em->persist($user);
            $this->em->flush();
        }

        $jwt = $this->jwtManager->create($user);

        $userData = $this->normalizer->normalize($user, null, ['groups' => 'user:read']);

        $this->logger->info("✅ Usuario autenticado exitosamente con email: {$email}.");

        return new JsonResponse([
            'token' => $jwt,
            'user' => $userData
        ]);
    }
}