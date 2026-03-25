<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\RequestQuestion;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class RequestQuestionProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persistProcessor,
        private readonly LoggerInterface $logger,
        private readonly Security $security
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if ($data instanceof RequestQuestion && $operation instanceof Post) {
            $user = $this->security->getUser();
            if (!$user) {
                $this->logger->warning('Intento de crear una pregunta sin estar logueado.');
                throw new AccessDeniedHttpException('Debes estar logueado para crear una pregunta.');
            }

            if ($user) {
                $data->setAuthor($user);
            }
            $this->logger->info('Creando una nueva pregunta de solicitud por parte del usuario: ' . $user->getUserIdentifier());

        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}