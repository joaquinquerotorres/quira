<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260330120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Request: estimated_price_min/max (céntimos) a partir de price_amount (backfill temporal)';
    }

    public function up(Schema $schema): void
    {
        // Añadimos primero con default para evitar errores por NOT NULL.
        $this->addSql('ALTER TABLE request ADD estimated_price_min INT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE request ADD estimated_price_max INT NOT NULL DEFAULT 0');

        // Backfill temporal: si antes teníamos solo precio único del cliente (price_amount),
        // copiamos ese valor como rango [min,max].
        $this->addSql('UPDATE request SET estimated_price_min = COALESCE(price_amount, 0), estimated_price_max = COALESCE(price_amount, 0)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE request DROP estimated_price_min');
        $this->addSql('ALTER TABLE request DROP estimated_price_max');
    }
}

