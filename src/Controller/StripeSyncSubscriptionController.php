<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\StripeSubscriptionSyncService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Tras checkout, si el webhook aún no actualizó la BD, el cliente puede llamar este endpoint
 * con el JWT del usuario para forzar sincronización desde Stripe → paidThroughAt.
 */
#[AsController]
#[IsGranted('ROLE_USER')]
final class StripeSyncSubscriptionController extends AbstractController
{
    public function __construct(
        private readonly StripeSubscriptionSyncService $subscriptionSyncService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/api/stripe/sync-subscription', name: 'api_stripe_sync_subscription', methods: ['POST'])]
    public function __invoke(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $profile = $user->getProfessionalProfile();

        if ($profile === null) {
            return new JsonResponse(['message' => 'No tienes perfil profesional.'], Response::HTTP_FORBIDDEN);
        }

        $this->subscriptionSyncService->syncCustomerSubscriptionsFromStripe($user);

        $profile = $user->getProfessionalProfile();
        if ($profile !== null) {
            $this->entityManager->refresh($profile);
        }
        $paidThrough = $profile?->getPaidThroughAt();

        return new JsonResponse([
            'paidThroughAt' => $paidThrough?->format(\DateTimeInterface::ATOM),
            'subscriptionCancelAtPeriodEnd' => $profile?->isSubscriptionCancelAtPeriodEnd() ?? false,
        ], Response::HTTP_OK);
    }
}
