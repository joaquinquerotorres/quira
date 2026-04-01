<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\BidRepository;
use App\Service\ProfessionalSubscriptionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AsController]
#[IsGranted('ROLE_USER')]
class CanBidController extends AbstractController
{
    public function __construct(
        private readonly BidRepository $bidRepository,
        private readonly ProfessionalSubscriptionService $subscriptionService,
    ) {
    }

    #[Route('/api/professionals/me/can-bid', name: 'api_professionals_can_bid', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($user->getProfessionalProfile() === null) {
            return new JsonResponse([
                'canBidThisMonth' => false,
                'remainingBidsThisMonth' => 0,
            ], 200);
        }

        if (!$this->subscriptionService->isSubjectToFreeProfessionalLimits($user)) {
            return new JsonResponse([
                'canBidThisMonth' => true,
                'remainingBidsThisMonth' => null,
            ], 200);
        }

        $usedBids = $this->bidRepository->countByProfessionalThisMonth($user);
        $remainingBids = max(0, BidRepository::BIDS_MONTHLY_LIMIT_FREE - $usedBids);
        $canBid = $remainingBids > 0;

        return new JsonResponse([
            'canBidThisMonth' => $canBid,
            'remainingBidsThisMonth' => $remainingBids,
        ], 200);
    }
}
