<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260729120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'pricing_rate catalog + harden gemini_cache (hash/model/zoneKey); drop API exposure fields migration';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE pricing_rate (id INT AUTO_INCREMENT NOT NULL, category_code VARCHAR(50) NOT NULL, category_label VARCHAR(100) NOT NULL, subcategory VARCHAR(255) NOT NULL, zone VARCHAR(50) NOT NULL, price_min INT NOT NULL, price_max INT NOT NULL, unit VARCHAR(50) NOT NULL, complexity VARCHAR(20) NOT NULL, INDEX idx_pricing_rate_zone (zone), INDEX idx_pricing_rate_category_code (category_code), UNIQUE INDEX uniq_pricing_rate_cat_sub_zone (category_label, subcategory, zone), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');

        // gemini_cache: add new columns (table already exists from earlier migration)
        $this->addSql('ALTER TABLE gemini_cache ADD content_hash VARCHAR(64) NOT NULL DEFAULT \'\'');
        $this->addSql('ALTER TABLE gemini_cache ADD zone_key VARCHAR(120) NOT NULL DEFAULT \'\'');
        $this->addSql('ALTER TABLE gemini_cache CHANGE model model VARCHAR(80) NOT NULL');
        $this->addSql('CREATE INDEX idx_gemini_cache_lookup ON gemini_cache (model, content_hash, expires_at)');
        // Expire existing rows so they are recreated with hash/zone
        $this->addSql('UPDATE gemini_cache SET expires_at = DATE_SUB(NOW(), INTERVAL 1 DAY)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE pricing_rate');
        $this->addSql('DROP INDEX idx_gemini_cache_lookup ON gemini_cache');
        $this->addSql('ALTER TABLE gemini_cache DROP content_hash');
        $this->addSql('ALTER TABLE gemini_cache DROP zone_key');
        $this->addSql('ALTER TABLE gemini_cache CHANGE model model VARCHAR(50) NOT NULL');
    }
}
