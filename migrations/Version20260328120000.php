<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260328120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Stripe webhook idempotency (stripe_webhook_event)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE stripe_webhook_event (id VARCHAR(255) NOT NULL, type VARCHAR(120) NOT NULL, processed_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE INDEX idx_stripe_webhook_event_processed ON stripe_webhook_event (processed_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE stripe_webhook_event');
    }
}
