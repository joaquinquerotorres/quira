<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260302225141 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add subscription_cancel_at_period_end to professional_profile for Stripe cancel-at-period-end state.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE professional_profile ADD subscription_cancel_at_period_end TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE professional_profile DROP subscription_cancel_at_period_end');
    }
}
