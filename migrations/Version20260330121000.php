<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260330121000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Request: elimina columna legacy price_amount (usar estimated_price_min/max en céntimos)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE request DROP price_amount');
    }

    public function down(Schema $schema): void
    {
        // Back to legacy single price. Valores antiguos ya no se conocen con certeza.
        $this->addSql('ALTER TABLE request ADD price_amount INT DEFAULT NULL');
    }
}

