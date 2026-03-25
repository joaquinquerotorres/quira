<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\StripeCheckoutInput;
use App\Entity\User;
use App\Repository\ProfessionalProfileRepository;
use App\Service\StripeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AsController]
#[IsGranted('ROLE_USER')]
class StripeCheckoutController extends AbstractController
{
    public function __construct(
        private readonly StripeService $stripeService,
        private readonly ProfessionalProfileRepository $professionalProfileRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/api/stripe/checkout-session', name: 'api_stripe_checkout_session', methods: ['POST'])]
    public function __invoke(
        #[MapRequestPayload] StripeCheckoutInput $input
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();

        $profile = $this->professionalProfileRepository->find($input->professionalProfileId);
        if ($profile === null) {
            throw new BadRequestHttpException('Perfil profesional no encontrado.');
        }

        if ($profile->getUser() !== $user) {
            throw new AccessDeniedHttpException('Solo puedes crear sesiones de pago para tu propio perfil.');
        }

        $customerId = $this->stripeService->getOrCreateCustomer($user);

        if ($user->getStripeCustomerId() === null) {
            $user->setStripeCustomerId($customerId);
            $this->entityManager->flush();
        }

        $url = $this->stripeService->createCheckoutSession(
            $profile,
            $input->tier,
            $input->successUrl,
            $input->cancelUrl,
            $customerId
        );

        return new JsonResponse(['url' => $url]);
    }
}
