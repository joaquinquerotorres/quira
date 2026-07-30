<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\CalendarEvent;
use App\Entity\Request;
use App\Entity\User;
use App\Enum\RequestStatus;
use App\Repository\CalendarEventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class CalendarEventOwnerProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persistProcessor,
        #[Autowire(service: 'api_platform.doctrine.orm.state.remove_processor')]
        private readonly ProcessorInterface $removeProcessor,
        private readonly Security $security,
        private readonly CalendarEventRepository $calendarEventRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = []
    ): mixed {
        if (!$data instanceof CalendarEvent) {
            return $data;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException('Debes estar logueado para gestionar el calendario.');
        }

        $proProfile = $user->getProfessionalProfile();
        if ($proProfile === null) {
            throw new AccessDeniedHttpException('Necesitas un perfil profesional para gestionar el calendario.');
        }

        if ($operation instanceof Delete) {
            $this->assertOwner($data, $proProfile->getId());
            $this->logger->info(sprintf(
                'Usuario %s elimina calendar_event %d',
                $user->getUserIdentifier(),
                $data->getId() ?? 0
            ));

            return $this->removeProcessor->process($data, $operation, $uriVariables, $context);
        }

        if ($operation instanceof Post) {
            $request = $data->getRequest();
            if ($request === null) {
                throw new BadRequestHttpException('Debes indicar la solicitud del trabajo.');
            }

            // Releer la request desde BD por si llega como IRI parcial.
            if ($request->getId() !== null) {
                $managed = $this->entityManager->find(Request::class, $request->getId());
                if ($managed !== null) {
                    $request = $managed;
                    $data->setRequest($request);
                }
            }

            if (!\in_array($request->getStatus(), [RequestStatus::ACCEPTED, RequestStatus::COMPLETED], true)) {
                throw new BadRequestHttpException('Solo puedes agendar trabajos aceptados o completados.');
            }

            if ($request->getAssignedProfessional() !== $proProfile) {
                throw new AccessDeniedHttpException('Solo puedes agendar trabajos que te han asignado.');
            }

            $this->assertValidStartsAt($data);

            $existing = $this->calendarEventRepository->findOneByRequestAndProfessional($request, $proProfile);
            if ($existing !== null) {
                // Upsert: un solo evento por trabajo; POST con el mismo request actualiza startsAt/notes.
                $existing->setStartsAt($data->getStartsAt());
                $existing->setNotes($data->getNotes());
                $this->assertValidStartsAt($existing);

                $this->logger->info(sprintf(
                    'Usuario %s actualiza calendar_event %d (upsert POST) para request %d',
                    $user->getUserIdentifier(),
                    $existing->getId() ?? 0,
                    $request->getId() ?? 0
                ));

                return $this->persistProcessor->process($existing, $operation, $uriVariables, $context);
            }

            $data->setProfessional($proProfile);

            $this->logger->info(sprintf(
                'Usuario %s crea calendar_event para request %d',
                $user->getUserIdentifier(),
                $request->getId() ?? 0
            ));

            return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
        }

        if ($operation instanceof Patch) {
            $this->assertOwner($data, $proProfile->getId());
            $this->assertValidStartsAt($data);

            return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }

    private function assertOwner(CalendarEvent $event, ?int $proProfileId): void
    {
        if ($event->getProfessional()?->getId() !== $proProfileId) {
            throw new AccessDeniedHttpException('Solo puedes gestionar eventos de tu propio calendario.');
        }
    }

    private function assertValidStartsAt(CalendarEvent $event): void
    {
        if ($event->getStartsAt() === null) {
            throw new BadRequestHttpException('Debes indicar la fecha y hora de comienzo del trabajo.');
        }
    }
}
