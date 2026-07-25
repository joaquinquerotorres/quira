<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Bid;
use App\Entity\Request;
use App\Entity\VisitRequest;
use App\Enum\BidStatus;
use App\Enum\RequestStatus;
use App\Enum\RiskLevel;
use App\Repository\RequestRepository;
use App\Repository\VisitRequestRepository;
use App\Service\ProfessionalSubscriptionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api')]
final class VisitRequestController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RequestRepository $requestRepository,
        private readonly VisitRequestRepository $visitRequestRepository,
        private readonly ProfessionalSubscriptionService $subscriptionService,
    ) {
    }

    #[Route('/requests/{id}/visit-request', name: 'api_request_visit_request', methods: ['POST'])]
    #[IsGranted('ROLE_PROFESSIONAL')]
    public function createVisitRequest(int $id, HttpRequest $request): JsonResponse
    {
        /** @var \App\Entity\User|null $user */
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['message' => 'No autenticado.'], Response::HTTP_UNAUTHORIZED);
        }

        $proProfile = $user->getProfessionalProfile();
        if ($proProfile === null) {
            return new JsonResponse(['message' => 'Debes tener un perfil profesional para solicitar una visita.'], Response::HTTP_FORBIDDEN);
        }

        $serviceRequest = $this->requestRepository->find($id);
        if (!$serviceRequest instanceof Request) {
            return new JsonResponse(['message' => 'Solicitud no encontrada.'], Response::HTTP_NOT_FOUND);
        }

        if ($serviceRequest->getStatus() !== RequestStatus::PENDING) {
            return new JsonResponse(['message' => 'Solo puedes pedir una visita para solicitudes pendientes.'], Response::HTTP_BAD_REQUEST);
        }

        if ($serviceRequest->getRiskLevel() === RiskLevel::HIGH) {
            if (!in_array('ROLE_PRO', $user->getRoles(), true) || !$this->subscriptionService->hasActivePaidSubscription($proProfile)) {
                return new JsonResponse(
                    ['message' => 'Solo un profesional PRO con suscripción activa puede solicitar visita en solicitudes HIGH.'],
                    Response::HTTP_FORBIDDEN
                );
            }
        }

        if ($this->resolvePricingType($serviceRequest) !== Request::PRICING_TYPE_VISIT_REQUIRED) {
            return new JsonResponse(
                ['message' => 'Esta solicitud no requiere visita de valoración según el diagnóstico.'],
                Response::HTTP_BAD_REQUEST
            );
        }

        // Evitar duplicados por request + profesional
        $existing = $this->visitRequestRepository->findOneBy([
            'request' => $serviceRequest,
            'professional' => $proProfile,
        ]);
        if ($existing instanceof VisitRequest) {
            return $this->json($existing, Response::HTTP_OK, [], ['groups' => ['visit:read']]);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $note = isset($data['note']) && \is_string($data['note']) ? trim($data['note']) : null;

        $visit = new VisitRequest();
        $visit->setRequest($serviceRequest)
            ->setProfessional($proProfile)
            ->setStatus(VisitRequest::STATUS_PENDING)
            ->setNote($note);

        $this->em->persist($visit);
        $this->em->flush();

        return $this->json($visit, Response::HTTP_CREATED, [], ['groups' => ['visit:read']]);
    }

    #[Route('/visit-requests/{id}/accept', name: 'api_visit_request_accept', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function acceptVisitRequest(int $id): JsonResponse
    {
        return $this->changeVisitRequestStatus($id, VisitRequest::STATUS_ACCEPTED);
    }

    #[Route('/visit-requests/{id}/reject', name: 'api_visit_request_reject', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function rejectVisitRequest(int $id): JsonResponse
    {
        return $this->changeVisitRequestStatus($id, VisitRequest::STATUS_REJECTED);
    }

    #[Route('/requests/{id}/visit-quote', name: 'api_request_visit_quote', methods: ['POST'])]
    #[IsGranted('ROLE_PROFESSIONAL')]
    public function createVisitQuote(int $id, HttpRequest $request): JsonResponse
    {
        /** @var \App\Entity\User|null $user */
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['message' => 'No autenticado.'], Response::HTTP_UNAUTHORIZED);
        }

        $proProfile = $user->getProfessionalProfile();
        if ($proProfile === null) {
            return new JsonResponse(['message' => 'Debes tener un perfil profesional para crear un presupuesto de visita.'], Response::HTTP_FORBIDDEN);
        }

        $serviceRequest = $this->requestRepository->find($id);
        if (!$serviceRequest instanceof Request) {
            return new JsonResponse(['message' => 'Solicitud no encontrada.'], Response::HTTP_NOT_FOUND);
        }

        if ($serviceRequest->getStatus() !== RequestStatus::PENDING) {
            return new JsonResponse(['message' => 'Solo puedes crear un presupuesto para solicitudes pendientes.'], Response::HTTP_BAD_REQUEST);
        }

        $visit = $this->visitRequestRepository->findOneBy([
            'request' => $serviceRequest,
            'professional' => $proProfile,
            'status' => VisitRequest::STATUS_ACCEPTED,
        ]);

        if (!$visit instanceof VisitRequest) {
            return new JsonResponse(['message' => 'No tienes una visita aceptada para esta solicitud.'], Response::HTTP_BAD_REQUEST);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $amount = $data['amount'] ?? null;
        $comment = isset($data['comment']) && \is_string($data['comment']) ? trim($data['comment']) : null;

        if (!\is_int($amount) || $amount <= 0) {
            return new JsonResponse(['message' => 'El campo "amount" debe ser un entero positivo (en céntimos).'], Response::HTTP_BAD_REQUEST);
        }

        $bid = new Bid();
        $bid->setRequest($serviceRequest)
            ->setProfessional($user)
            ->setPriceQuote($amount)
            ->setComment($comment)
            ->setStatus(BidStatus::PENDING);

        $this->em->persist($bid);
        $this->em->flush();

        return $this->json($bid, Response::HTTP_CREATED, [], ['groups' => ['bid:read']]);
    }

    private function changeVisitRequestStatus(int $id, string $newStatus): JsonResponse
    {
        /** @var \App\Entity\User|null $user */
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['message' => 'No autenticado.'], Response::HTTP_UNAUTHORIZED);
        }

        $visit = $this->visitRequestRepository->find($id);
        if (!$visit instanceof VisitRequest) {
            return new JsonResponse(['message' => 'Solicitud de visita no encontrada.'], Response::HTTP_NOT_FOUND);
        }

        $request = $visit->getRequest();
        $client = $request?->getClient()?->getUser();
        if ($client === null || $client !== $user) {
            return new JsonResponse(['message' => 'Solo el cliente dueño de la solicitud puede gestionar la visita.'], Response::HTTP_FORBIDDEN);
        }

        if ($visit->getStatus() !== VisitRequest::STATUS_PENDING) {
            return new JsonResponse(['message' => 'Solo se pueden aceptar o rechazar visitas pendientes.'], Response::HTTP_BAD_REQUEST);
        }

        $visit->setStatus($newStatus);
        $visit->touch();
        $this->em->flush();

        return $this->json($visit, Response::HTTP_OK, [], ['groups' => ['visit:read']]);
    }

    private function resolvePricingType(Request $request): string
    {
        $pricingType = $request->getPricingType();
        if (is_string($pricingType) && $pricingType !== '') {
            return strtoupper($pricingType);
        }

        $aiDiagnosis = $request->getAiDiagnosis();
        $raw = is_array($aiDiagnosis) ? ($aiDiagnosis['pricing_type'] ?? $aiDiagnosis['pricingType'] ?? '') : '';

        return is_string($raw) ? strtoupper(trim($raw)) : '';
    }
}

