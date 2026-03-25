<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ProfessionalProfile;
use App\Entity\User;
use Stripe\Checkout\Session;
use Stripe\Customer;
use Stripe\Exception\ApiErrorException;
use Stripe\Subscription;
use Stripe\StripeClient;

class StripeService
{
    public function __construct(
        private readonly string $secretKey,
        private readonly string $priceSolver,
        private readonly string $pricePro,
    ) {
    }

    /**
     * @throws ApiErrorException
     */
    public function getOrCreateCustomer(User $user): string
    {
        if ($user->getStripeCustomerId() !== null) {
            return $user->getStripeCustomerId();
        }

        $client = new StripeClient($this->secretKey);
        $customer = $client->customers->create([
            'email' => $user->getEmail(),
            'metadata' => [
                'user_id' => (string) $user->getId(),
            ],
        ]);

        return $customer->id;
    }

    /**
     * @param 'SOLVER'|'PRO' $tier
     * @throws ApiErrorException
     */
    public function createCheckoutSession(
        ProfessionalProfile $profile,
        string $tier,
        string $successUrl,
        string $cancelUrl,
        string $customerId
    ): string {
        $priceId = $tier === 'PRO' ? $this->pricePro : $this->priceSolver;

        $client = new StripeClient($this->secretKey);
        $session = $client->checkout->sessions->create([
            'mode' => 'subscription',
            'customer' => $customerId,
            'line_items' => [
                [
                    'price' => $priceId,
                    'quantity' => 1,
                ],
            ],
            'metadata' => [
                'professionalProfileId' => (string) $profile->getId(),
                'tier' => $tier,
            ],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
        ]);

        return $session->url ?? '';
    }

    /**
     * @throws ApiErrorException
     */
    public function getSessionWithSubscription(string $sessionId): Session
    {
        $client = new StripeClient($this->secretKey);
        return $client->checkout->sessions->retrieve(
            $sessionId,
            ['expand' => ['subscription']]
        );
    }

    /**
     * Cancela (al final del periodo actual) todas las suscripciones activas del cliente.
     *
     * @throws ApiErrorException
     */
    public function cancelActiveSubscriptionsForCustomer(string $customerId): void
    {
        $client = new StripeClient($this->secretKey);

        $subscriptions = $client->subscriptions->all([
            'customer' => $customerId,
            'status' => 'active',
            'limit' => 100,
        ]);

        /** @var Subscription $subscription */
        foreach ($subscriptions->autoPagingIterator() as $subscription) {
            $client->subscriptions->update($subscription->id, [
                'cancel_at_period_end' => true,
            ]);
        }
    }
}
