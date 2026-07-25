<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ProfessionalProfile;
use App\Entity\User;
use Stripe\Checkout\Session;
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

        // Evitar suscripciones apiladas al cambiar de plan / rehacer checkout.
        $this->cancelSubscriptionsImmediatelyForCustomer($customerId);

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
            'subscription_data' => [
                'metadata' => [
                    'professionalProfileId' => (string) $profile->getId(),
                    'tier' => $tier,
                ],
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
            ['expand' => ['subscription', 'subscription.items.data']]
        );
    }

    /**
     * @throws ApiErrorException
     */
    public function retrieveSubscription(string $subscriptionId): Subscription
    {
        $client = new StripeClient($this->secretKey);

        return $client->subscriptions->retrieve($subscriptionId, [
            'expand' => ['items.data'],
        ]);
    }

    /**
     * Cancela (al final del periodo actual) todas las suscripciones activas/trialing/past_due del cliente.
     *
     * @throws ApiErrorException
     */
    public function cancelActiveSubscriptionsForCustomer(string $customerId): void
    {
        $client = new StripeClient($this->secretKey);

        foreach (['active', 'trialing', 'past_due'] as $status) {
            $subscriptions = $client->subscriptions->all([
                'customer' => $customerId,
                'status' => $status,
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

    /**
     * Cancela de inmediato suscripciones activas/trialing/past_due (p.ej. antes de un nuevo checkout).
     *
     * @throws ApiErrorException
     */
    public function cancelSubscriptionsImmediatelyForCustomer(string $customerId): void
    {
        $client = new StripeClient($this->secretKey);

        foreach (['active', 'trialing', 'past_due'] as $status) {
            $subscriptions = $client->subscriptions->all([
                'customer' => $customerId,
                'status' => $status,
                'limit' => 100,
            ]);

            /** @var Subscription $subscription */
            foreach ($subscriptions->autoPagingIterator() as $subscription) {
                $client->subscriptions->cancel($subscription->id);
            }
        }
    }

    /**
     * Periodo de facturación: en API basil+ vive en SubscriptionItem; fallback a Subscription.
     */
    public static function extractSubscriptionPeriodEnd(object $subscription): ?int
    {
        $ends = [];

        $items = $subscription->items->data ?? null;
        if (\is_array($items) || $items instanceof \Traversable) {
            foreach ($items as $item) {
                $itemEnd = $item->current_period_end ?? null;
                if ($itemEnd !== null) {
                    $ends[] = (int) $itemEnd;
                }
            }
        }

        $legacy = $subscription->current_period_end ?? null;
        if ($legacy !== null) {
            $ends[] = (int) $legacy;
        }

        return $ends === [] ? null : max($ends);
    }

    /**
     * ID de suscripción en Invoice: API basil+ usa parent.subscription_details.
     */
    public static function extractInvoiceSubscriptionId(object $invoice): ?string
    {
        $sub = $invoice->subscription ?? null;
        if (\is_string($sub) && $sub !== '') {
            return $sub;
        }
        if (\is_object($sub) && isset($sub->id) && \is_string($sub->id) && $sub->id !== '') {
            return $sub->id;
        }

        $parent = $invoice->parent ?? null;
        $details = \is_object($parent) ? ($parent->subscription_details ?? null) : null;
        $fromParent = \is_object($details) ? ($details->subscription ?? null) : null;
        if (\is_string($fromParent) && $fromParent !== '') {
            return $fromParent;
        }
        if (\is_object($fromParent) && isset($fromParent->id) && \is_string($fromParent->id) && $fromParent->id !== '') {
            return $fromParent->id;
        }

        return null;
    }

    /**
     * Todas las suscripciones del cliente (cualquier estado), para reconciliación.
     *
     * @return \Iterator<int, Subscription>
     *
     * @throws ApiErrorException
     */
    public function listAllSubscriptionsForCustomer(string $customerId): \Iterator
    {
        $client = new StripeClient($this->secretKey);
        $collection = $client->subscriptions->all([
            'customer' => $customerId,
            'status' => 'all',
            'limit' => 100,
        ]);

        return $collection->autoPagingIterator();
    }
}
