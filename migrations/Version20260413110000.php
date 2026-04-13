<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260413110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Request/Bid: persist pricing type and support range bids';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE request ADD pricing_type VARCHAR(20) DEFAULT NULL');
        $this->addSql("UPDATE request SET pricing_type = UPPER(JSON_UNQUOTE(JSON_EXTRACT(ai_diagnosis, '$.pricing_type'))) WHERE ai_diagnosis IS NOT NULL AND JSON_EXTRACT(ai_diagnosis, '$.pricing_type') IS NOT NULL");
        $this->addSql("UPDATE request SET pricing_type = UPPER(JSON_UNQUOTE(JSON_EXTRACT(ai_diagnosis, '$.pricingType'))) WHERE pricing_type IS NULL AND ai_diagnosis IS NOT NULL AND JSON_EXTRACT(ai_diagnosis, '$.pricingType') IS NOT NULL");
        $this->addSql("UPDATE request SET pricing_type = NULL WHERE pricing_type NOT IN ('FIXED','RANGE','VISIT_REQUIRED')");

        $this->addSql("ALTER TABLE bid ADD pricing_type VARCHAR(20) NOT NULL DEFAULT 'FIXED', ADD price_quote_min INT DEFAULT NULL, ADD price_quote_max INT DEFAULT NULL");
        $this->addSql('UPDATE bid SET price_quote_min = price_quote, price_quote_max = price_quote WHERE price_quote IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE request DROP pricing_type');
        $this->addSql('ALTER TABLE bid DROP pricing_type, DROP price_quote_min, DROP price_quote_max');
    }
}

