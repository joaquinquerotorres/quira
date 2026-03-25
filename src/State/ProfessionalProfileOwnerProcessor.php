<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\ProfessionalProfile;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class ProfessionalProfileOwnerProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persistProcessor,
        private readonly LoggerInterface $logger,
        private readonly Security $security,
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = []
    ): mixed {
        if (!$data instanceof ProfessionalProfile) {
            $this->logger->warning('Intento de procesar un objeto que no es un perfil profesional.');
            return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
        }

        /** @var User|null $user */
        $user = $this->security->getUser();
        if (!$user) {
            $this->logger->warning('Intento de crear un perfil profesional sin estar logueado.');
            throw new AccessDeniedHttpException('Debes estar logueado para crear un perfil profesional.');
        }

        if ($operation instanceof Post) {
            if ($user->getProfessionalProfile() !== null) {
                $this->logger->warning("Usuario {$user->getUserIdentifier()} intentó crear un segundo perfil profesional.");
                throw new ConflictHttpException('Ya tienes un perfil profesional creado.');
            }
            $data->setUser($user);
            $data->setIsVerified(false);
        }

        $tier = $data->getTierRequested();
        
        if ($tier) {
            $roles = $user->getRoles();
            
            $roles = array_diff($roles, ['ROLE_PRO', 'ROLE_SOLVER', 'ROLE_FREE']);
            
            if (!in_array('ROLE_PROFESSIONAL', $roles)) {
                $roles[] = 'ROLE_PROFESSIONAL';
            }

            switch ($tier) {
                case 'PRO':
                    $roles[] = 'ROLE_PRO';
                    if (!$data->getPaidThroughAt()) {
                        $data->setPaidThroughAt(new \DateTimeImmutable('+6 months'));
                    }
                    break;
                case 'SOLVER':
                    $roles[] = 'ROLE_SOLVER';
                    if (!$data->getPaidThroughAt()) {
                        $data->setPaidThroughAt(new \DateTimeImmutable('+6 months'));
                    }
                    break;
                case 'FREE':
                default:
                    $roles[] = 'ROLE_FREE';
                    break;
            }

            $user->setRoles(array_unique($roles));
            $this->entityManager->persist($user);
            $this->entityManager->flush();
            $this->logger->info("Usuario {$user->getUserIdentifier()} ha solicitado el nivel {$tier} en su perfil profesional.");
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}