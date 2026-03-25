<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ProfessionalProfile;
use App\Entity\User;
use App\Repository\ProfessionalProfileRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Handles the logic when a Stripe Checkout Session is completed (webhook).
 */
final class StripeCheckoutSessionHandler
{
    public function __construct(
        private readonly StripeService $stripeService,
        private readonly ProfessionalProfileRepository $professionalProfileRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function handleCompletedSession(object $session): void
    {
        $professionalProfileId = $session->metadata->professionalProfileId ?? null;
        $tier = $session->metadata->tier ?? null;

        if ($professionalProfileId === null || $tier === null) {
            throw new \InvalidArgumentException('Missing metadata');
        }

        $profile = $this->professionalProfileRepository->find((int) $professionalProfileId);
        if ($profile === null) {
            throw new \InvalidArgumentException('Professional profile not found');
        }

        $subscriptionId = $session->subscription ?? null;
        if ($subscriptionId === null) {
            throw new \InvalidArgumentException('No subscription');
        }

        $stripeSession = $this->stripeService->getSessionWithSubscription($session->id);
        $subscription = $stripeSession->subscription;

        if ($subscription === null || is_string($subscription) || !isset($subscription->current_period_end)) {
            throw new \InvalidArgumentException('Could not get subscription period');
        }

        $currentPeriodEnd = (int) $subscription->current_period_end;
        $paidThroughAt = \DateTimeImmutable::createFromFormat('U', (string) $currentPeriodEnd);

        if ($paidThroughAt === false) {
            throw new \InvalidArgumentException('Invalid period end');
        }

        $profile->setPaidThroughAt($paidThroughAt);
        $profile->setSubscriptionCancelAtPeriodEnd(false);

        $user = $profile->getUser();
        $roles = array_filter(
            $user->getRoles(),
            fn(string $r) => !in_array($r, ['ROLE_FREE', 'ROLE_PRO', 'ROLE_SOLVER'], true)
        );
        $roles[] = $tier === 'PRO' ? 'ROLE_PRO' : 'ROLE_SOLVER';
        $user->setRoles(array_values(array_unique($roles)));

        $this->entityManager->flush();
    }
}
