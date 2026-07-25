<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ProfessionalProfile;
use App\Entity\User;
use App\Repository\ProfessionalProfileRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Stripe\Invoice;
use Stripe\Subscription;

/**
 * Mantiene paidThroughAt y subscriptionCancelAtPeriodEnd alineados con Stripe
 * (current_period_end, cancel_at_period_end, estados finales).
 */
final class StripeSubscriptionSyncService
{
    public function __construct(
        private readonly StripeService $stripeService,
        private readonly ProfessionalProfileRepository $professionalProfileRepository,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function syncFromSubscriptionId(string $subscriptionId): void
    {
        try {
            $subscription = $this->stripeService->retrieveSubscription($subscriptionId);
        } catch (\Throwable $e) {
            $this->logger->warning('Stripe subscription retrieve failed: {message}', [
                'message' => $e->getMessage(),
                'subscriptionId' => $subscriptionId,
            ]);

            return;
        }

        $this->applySubscription($subscription);
    }

    public function applySubscription(Subscription $subscription): void
    {
        $profile = $this->resolveProfile($subscription);
        if ($profile === null) {
            $this->logger->notice('Stripe subscription sync: no professional profile for subscription {id}', [
                'id' => $subscription->id,
            ]);

            return;
        }

        $paidThrough = $this->computePaidThroughAt($subscription);
        if ($paidThrough !== null) {
            $profile->setPaidThroughAt($paidThrough);
        }

        $profile->setSubscriptionCancelAtPeriodEnd((bool) $subscription->cancel_at_period_end);

        $this->entityManager->flush();
    }

    public function syncFromInvoice(Invoice $invoice): void
    {
        $id = StripeService::extractInvoiceSubscriptionId($invoice);
        if ($id === null || $id === '') {
            return;
        }

        $this->syncFromSubscriptionId($id);
    }

    private function resolveProfile(Subscription $subscription): ?ProfessionalProfile
    {
        $meta = $subscription->metadata ?? null;
        $profileId = null;
        if ($meta !== null && isset($meta['professionalProfileId'])) {
            $profileId = (string) $meta['professionalProfileId'];
        }

        if ($profileId !== null && $profileId !== '') {
            $profile = $this->professionalProfileRepository->find((int) $profileId);
            if ($profile instanceof ProfessionalProfile) {
                return $profile;
            }
        }

        $customerId = $subscription->customer;
        if (!\is_string($customerId) || $customerId === '') {
            return null;
        }

        $user = $this->userRepository->findOneByStripeCustomerId($customerId);

        return $user?->getProfessionalProfile();
    }

    private function computePaidThroughAt(Subscription $subscription): ?\DateTimeImmutable
    {
        $cpe = StripeService::extractSubscriptionPeriodEnd($subscription);
        if ($cpe === null) {
            return null;
        }

        $periodEnd = \DateTimeImmutable::createFromFormat('U', (string) $cpe);
        if ($periodEnd === false) {
            return null;
        }

        $status = (string) $subscription->status;
        if (\in_array($status, [Subscription::STATUS_CANCELED, Subscription::STATUS_UNPAID, Subscription::STATUS_INCOMPLETE_EXPIRED], true)) {
            $ended = $subscription->ended_at ?? null;
            if ($ended !== null) {
                $endedAt = \DateTimeImmutable::createFromFormat('U', (string) (int) $ended);
                if ($endedAt !== false && $endedAt < $periodEnd) {
                    return $endedAt;
                }
            }
        }

        return $periodEnd;
    }

    /**
     * Trae suscripciones desde la API de Stripe y aplica la canónica (activa / trialing / past_due
     * con mayor current_period_end; si no hay, la más reciente por periodo entre el resto).
     */
    public function syncCustomerSubscriptionsFromStripe(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        $customerId = $user->getStripeCustomerId();
        if ($customerId === null || $customerId === '') {
            return false;
        }

        try {
            $subscriptions = iterator_to_array($this->stripeService->listAllSubscriptionsForCustomer($customerId), false);
        } catch (\Throwable $e) {
            $this->logger->warning('Stripe list subscriptions failed: {message}', [
                'message' => $e->getMessage(),
                'customerId' => $customerId,
            ]);

            return false;
        }

        if ($subscriptions === []) {
            $this->logger->notice('stripe.reconcile.no_subscriptions_for_customer', [
                'customerId' => $customerId,
                'userId' => $user->getId(),
            ]);

            return false;
        }

        $picked = $this->pickCanonicalSubscription($subscriptions);
        if ($picked === null) {
            return false;
        }

        $this->applySubscription($picked);
        $this->logger->info('stripe.reconcile.subscription_applied', [
            'customerId' => $customerId,
            'userId' => $user->getId(),
            'subscription_id' => $picked->id,
            'subscription_status' => $picked->status,
        ]);

        return true;
    }

    /**
     * @param list<Subscription> $subscriptions
     */
    private function pickCanonicalSubscription(array $subscriptions): ?Subscription
    {
        $activeLike = array_values(array_filter(
            $subscriptions,
            static fn (Subscription $s): bool => \in_array((string) $s->status, [
                Subscription::STATUS_ACTIVE,
                Subscription::STATUS_TRIALING,
                Subscription::STATUS_PAST_DUE,
            ], true)
        ));

        $pool = $activeLike !== [] ? $activeLike : $subscriptions;

        usort(
            $pool,
            static function (Subscription $a, Subscription $b): int {
                $ea = StripeService::extractSubscriptionPeriodEnd($a) ?? 0;
                $eb = StripeService::extractSubscriptionPeriodEnd($b) ?? 0;

                return $eb <=> $ea;
            }
        );

        return $pool[0] ?? null;
    }
}
