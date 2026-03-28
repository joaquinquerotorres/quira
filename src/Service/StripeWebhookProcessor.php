<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\StripeWebhookEvent;
use App\Repository\StripeWebhookEventRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Stripe\Event;
use Stripe\Invoice;
use Stripe\Subscription;

/**
 * Procesa eventos Stripe con logs estructurados e idempotencia por evt_*.
 */
final class StripeWebhookProcessor
{
    public function __construct(
        private readonly StripeCheckoutSessionHandler $sessionHandler,
        private readonly StripeSubscriptionSyncService $subscriptionSyncService,
        private readonly StripeWebhookEventRepository $webhookEventRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function process(Event $event): void
    {
        $context = [
            'stripe_event_id' => $event->id,
            'stripe_event_type' => $event->type,
        ];

        if ($this->webhookEventRepository->wasProcessed($event->id)) {
            $this->logger->info('stripe.webhook.skipped_duplicate', $context);

            return;
        }

        $this->logger->info('stripe.webhook.received', $context);

        $recordAfterSuccess = \in_array($event->type, self::recordedEventTypes(), true);

        try {
            match ($event->type) {
                Event::CHECKOUT_SESSION_COMPLETED => $this->handleCheckoutSessionCompleted($event, $context),
                Event::CUSTOMER_SUBSCRIPTION_CREATED,
                Event::CUSTOMER_SUBSCRIPTION_UPDATED,
                Event::CUSTOMER_SUBSCRIPTION_DELETED,
                Event::CUSTOMER_SUBSCRIPTION_PAUSED,
                Event::CUSTOMER_SUBSCRIPTION_RESUMED => $this->handleSubscriptionEvent($event, $context),
                Event::INVOICE_PAID,
                Event::INVOICE_PAYMENT_FAILED,
                Event::INVOICE_PAYMENT_SUCCEEDED,
                Event::INVOICE_UPDATED => $this->handleInvoiceEvent($event, $context),
                default => $this->logger->debug('stripe.webhook.unhandled_type', $context),
            };
        } catch (\Throwable $e) {
            $this->logger->error('stripe.webhook.handler_failed', $context + [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }

        if ($recordAfterSuccess) {
            $this->markProcessed($event->id, $event->type, $context);
        }
    }

    /**
     * Tipos que persistimos como procesados (evita reprocesar si añadimos lógica nueva a tipos ignorados).
     *
     * @return list<string>
     */
    private static function recordedEventTypes(): array
    {
        return [
            Event::CHECKOUT_SESSION_COMPLETED,
            Event::CUSTOMER_SUBSCRIPTION_CREATED,
            Event::CUSTOMER_SUBSCRIPTION_UPDATED,
            Event::CUSTOMER_SUBSCRIPTION_DELETED,
            Event::CUSTOMER_SUBSCRIPTION_PAUSED,
            Event::CUSTOMER_SUBSCRIPTION_RESUMED,
            Event::INVOICE_PAID,
            Event::INVOICE_PAYMENT_FAILED,
            Event::INVOICE_PAYMENT_SUCCEEDED,
            Event::INVOICE_UPDATED,
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    private function handleCheckoutSessionCompleted(Event $event, array $context): void
    {
        $session = $event->data->object;
        $this->sessionHandler->handleCompletedSession($session);
        $this->logger->info('stripe.webhook.checkout_session_completed_ok', $context + [
            'session_id' => $session->id ?? null,
        ]);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function handleSubscriptionEvent(Event $event, array $context): void
    {
        $object = $event->data->object;
        if (!$object instanceof Subscription) {
            return;
        }

        $this->subscriptionSyncService->applySubscription($object);
        $this->logger->info('stripe.webhook.subscription_synced', $context + [
            'subscription_id' => $object->id,
            'subscription_status' => $object->status,
        ]);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function handleInvoiceEvent(Event $event, array $context): void
    {
        $object = $event->data->object;
        if (!$object instanceof Invoice) {
            return;
        }

        $subId = $object->subscription ?? null;
        $this->subscriptionSyncService->syncFromInvoice($object);
        $this->logger->info('stripe.webhook.invoice_handled', $context + [
            'invoice_id' => $object->id,
            'invoice_subscription' => \is_string($subId) ? $subId : ($subId?->id ?? null),
        ]);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function markProcessed(string $eventId, string $type, array $context): void
    {
        $entity = new StripeWebhookEvent($eventId, $type);
        $this->entityManager->persist($entity);

        try {
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            if ($this->isUniqueConstraintViolation($e)) {
                $this->entityManager->detach($entity);
                $this->logger->notice('stripe.webhook.mark_processed_race', $context);

                return;
            }
            throw $e;
        }
    }

    private function isUniqueConstraintViolation(\Throwable $e): bool
    {
        if ($e instanceof UniqueConstraintViolationException) {
            return true;
        }

        $previous = $e->getPrevious();

        return $previous instanceof \Throwable && $this->isUniqueConstraintViolation($previous);
    }
}
