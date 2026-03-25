<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260227160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add indexes for optimized query performance: request, bid, notification, professional_profile, gemini_cache, refresh_tokens';
    }

    public function up(Schema $schema): void
    {
        // request
        $this->addSql('CREATE INDEX IDX_request_status ON request (status)');
        $this->addSql('CREATE INDEX IDX_request_status_created_at ON request (status, created_at)');
        $this->addSql('CREATE INDEX IDX_request_client_status ON request (client_id, status)');
        $this->addSql('CREATE INDEX IDX_request_pro_status ON request (assigned_professional_id, status)');
        $this->addSql('CREATE INDEX IDX_request_category ON request (category)');

        // bid
        $this->addSql('CREATE INDEX IDX_bid_status ON bid (status)');
        $this->addSql('CREATE INDEX IDX_bid_request_status ON bid (request_id, status)');
        $this->addSql('CREATE INDEX IDX_bid_professional_created ON bid (professional_id, created_at)');

        // notification
        $this->addSql('CREATE INDEX IDX_notification_user_created ON notification (user_id, created_at)');

        // professional_profile
        $this->addSql('CREATE INDEX IDX_professional_profile_verified ON professional_profile (is_verified)');
        $this->addSql('CREATE INDEX IDX_professional_profile_full_name ON professional_profile (full_name)');

        // gemini_cache
        $this->addSql('CREATE INDEX IDX_gemini_cache_expires ON gemini_cache (expires_at)');

        // refresh_tokens
        $this->addSql('CREATE INDEX IDX_refresh_tokens_username ON refresh_tokens (username)');
        $this->addSql('CREATE INDEX IDX_refresh_tokens_valid ON refresh_tokens (valid)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_request_status ON request');
        $this->addSql('DROP INDEX IDX_request_status_created_at ON request');
        $this->addSql('DROP INDEX IDX_request_client_status ON request');
        $this->addSql('DROP INDEX IDX_request_pro_status ON request');
        $this->addSql('DROP INDEX IDX_request_category ON request');

        $this->addSql('DROP INDEX IDX_bid_status ON bid');
        $this->addSql('DROP INDEX IDX_bid_request_status ON bid');
        $this->addSql('DROP INDEX IDX_bid_professional_created ON bid');

        $this->addSql('DROP INDEX IDX_notification_user_created ON notification');

        $this->addSql('DROP INDEX IDX_professional_profile_verified ON professional_profile');
        $this->addSql('DROP INDEX IDX_professional_profile_full_name ON professional_profile');

        $this->addSql('DROP INDEX IDX_gemini_cache_expires ON gemini_cache');

        $this->addSql('DROP INDEX IDX_refresh_tokens_username ON refresh_tokens');
        $this->addSql('DROP INDEX IDX_refresh_tokens_valid ON refresh_tokens');
    }
}
