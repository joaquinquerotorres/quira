<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\ClientProfile;
use App\Entity\User;
use App\Service\EmailVerificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UserRegistrationProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persistProcessor,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EmailVerificationService $emailVerificationService,
        private readonly LoggerInterface $logger,
        private readonly EntityManagerInterface $em
    ) {
    }

    /**
     * @param mixed $data
     * @param Operation $operation
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     * @return mixed
     */
    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = []
    ): mixed {
        if ($data instanceof User && $operation instanceof Post) {
            if ($data->getPassword()) {
                $hashedPassword = $this->passwordHasher->hashPassword(
                    $data,
                    $data->getPassword()
                );
                $data->setPassword($hashedPassword);
            }

            if ($data->getClientProfile() === null) {
                $clientProfile = new ClientProfile();
                $clientProfile->setUser($data);
                $data->setClientProfile($clientProfile);
            }

            $result = $this->persistProcessor->process($data, $operation, $uriVariables, $context);
            if ($result instanceof User) {
                $this->em->flush(); // asegura que el User tiene ID antes de crear el token
                try {
                    $this->logger->info('Enviando email de verificación a ' . $result->getUserIdentifier());
                    $this->emailVerificationService->sendVerificationEmail($result);
                } catch (\Throwable $e) {
                    $this->logger->error('Error enviando email de verificación', [
                        'message' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                    // No falla el registro; el usuario puede solicitar reenvío
                }
            }
            return $result;
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}