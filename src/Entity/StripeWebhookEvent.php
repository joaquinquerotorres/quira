<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\StripeWebhookEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Idempotencia de webhooks Stripe: un evt_* solo se procesa una vez con éxito.
 */
#[ORM\Entity(repositoryClass: StripeWebhookEventRepository::class)]
#[ORM\Table(name: 'stripe_webhook_event')]
#[ORM\Index(name: 'idx_stripe_webhook_event_processed', columns: ['processed_at'])]
class StripeWebhookEvent
{
    #[ORM\Id]
    #[ORM\Column(length: 255)]
    private string $id;

    #[ORM\Column(length: 120)]
    private string $type;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $processedAt;

    public function __construct(string $id, string $type)
    {
        $this->id = $id;
        $this->type = $type;
        $this->processedAt = new \DateTimeImmutable('now');
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getProcessedAt(): \DateTimeImmutable
    {
        return $this->processedAt;
    }
}
