<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260302112936 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX IDX_bid_professional_created ON bid');
        $this->addSql('DROP INDEX IDX_bid_request_status ON bid');
        $this->addSql('DROP INDEX IDX_bid_status ON bid');
        $this->addSql('DROP INDEX IDX_gemini_cache_expires ON gemini_cache');
        $this->addSql('DROP INDEX IDX_notification_user_created ON notification');
        $this->addSql('DROP INDEX IDX_professional_profile_full_name ON professional_profile');
        $this->addSql('DROP INDEX IDX_professional_profile_verified ON professional_profile');
        $this->addSql('DROP INDEX IDX_refresh_tokens_username ON refresh_tokens');
        $this->addSql('DROP INDEX IDX_refresh_tokens_valid ON refresh_tokens');
        $this->addSql('DROP INDEX IDX_request_pro_status ON request');
        $this->addSql('DROP INDEX IDX_request_client_status ON request');
        $this->addSql('DROP INDEX IDX_request_status_created_at ON request');
        $this->addSql('DROP INDEX IDX_request_status ON request');
        $this->addSql('DROP INDEX IDX_request_category ON request');
        $this->addSql('ALTER TABLE user ADD stripe_customer_id VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE INDEX IDX_bid_professional_created ON bid (professional_id, created_at)');
        $this->addSql('CREATE INDEX IDX_bid_request_status ON bid (request_id, status)');
        $this->addSql('CREATE INDEX IDX_bid_status ON bid (status)');
        $this->addSql('CREATE INDEX IDX_gemini_cache_expires ON gemini_cache (expires_at)');
        $this->addSql('CREATE INDEX IDX_notification_user_created ON notification (user_id, created_at)');
        $this->addSql('CREATE INDEX IDX_professional_profile_full_name ON professional_profile (full_name)');
        $this->addSql('CREATE INDEX IDX_professional_profile_verified ON professional_profile (is_verified)');
        $this->addSql('CREATE INDEX IDX_refresh_tokens_username ON refresh_tokens (username)');
        $this->addSql('CREATE INDEX IDX_refresh_tokens_valid ON refresh_tokens (valid)');
        $this->addSql('CREATE INDEX IDX_request_pro_status ON request (assigned_professional_id, status)');
        $this->addSql('CREATE INDEX IDX_request_client_status ON request (client_id, status)');
        $this->addSql('CREATE INDEX IDX_request_status_created_at ON request (status, created_at)');
        $this->addSql('CREATE INDEX IDX_request_status ON request (status)');
        $this->addSql('CREATE INDEX IDX_request_category ON request (category)');
        $this->addSql('ALTER TABLE `user` DROP stripe_customer_id');
    }
}
