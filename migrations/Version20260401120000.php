<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260401120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bid: elimina registros legacy REJECTED tras retirar ese estado del enum';
    }

    public function up(Schema $schema): void
    {
        // REJECTED representaba una retirada lógica; ahora las retiradas se borran físicamente.
        $this->addSql("DELETE FROM bid WHERE status = 'REJECTED'");
    }

    public function down(Schema $schema): void
    {
        // Irreversible: no se puede reconstruir qué bids retiradas existían antes.
    }
}

