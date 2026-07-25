<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ProfessionalProfile;
use App\Entity\User;
use App\Repository\ProfessionalProfileRepository;
use App\Service\StripeService;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\Exception\ApiErrorException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AsController]
#[IsGranted('ROLE_USER')]
class StripeCancelSubscriptionController extends AbstractController
{
    public function __construct(
        private readonly StripeService $stripeService,
        private readonly ProfessionalProfileRepository $professionalProfileRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/api/stripe/cancel-subscription', name: 'api_stripe_cancel_subscription', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $profileId = $data['professionalProfileId'] ?? null;

        if (!is_int($profileId) && !(is_string($profileId) && ctype_digit($profileId))) {
            throw new BadRequestHttpException('professionalProfileId inválido.');
        }

        /** @var User $user */
        $user = $this->getUser();

        /** @var ProfessionalProfile|null $profile */
        $profile = $this->professionalProfileRepository->find((int) $profileId);
        if ($profile === null) {
            throw new BadRequestHttpException('Perfil profesional no encontrado.');
        }

        if ($profile->getUser() !== $user) {
            throw new AccessDeniedHttpException('Solo puedes cancelar la suscripción de tu propio perfil.');
        }

        $customerId = $user->getStripeCustomerId();

        if ($customerId === null) {
            throw new BadRequestHttpException('No hay cliente de Stripe asociado a este usuario.');
        }

        try {
            $this->stripeService->cancelActiveSubscriptionsForCustomer($customerId);
        } catch (ApiErrorException $e) {
            throw new BadRequestHttpException('No se pudo cancelar la suscripción en Stripe. Inténtalo de nuevo más tarde.');
        }

        $profile->setSubscriptionCancelAtPeriodEnd(true);
        $this->entityManager->flush();

        return new JsonResponse([
            'success' => true,
        ]);
    }
}

