<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260305120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop origin and visit_request_id from bid; keep visit_request table';
    }

    public function up(Schema $schema): void
    {
        // Eliminar FK e índice antes de borrar la columna visit_request_id
        $this->addSql('ALTER TABLE bid DROP FOREIGN KEY FK_BID_VISIT_REQUEST_ID');
        $this->addSql('DROP INDEX IDX_BID_VISIT_REQUEST_ID ON bid');
        $this->addSql('ALTER TABLE bid DROP origin, DROP visit_request_id');
    }

    public function down(Schema $schema): void
    {
        // Restaurar columnas y FK tal y como estaban en la migración original
        $this->addSql("ALTER TABLE bid ADD origin VARCHAR(20) DEFAULT 'APP' NOT NULL, ADD visit_request_id INT DEFAULT NULL");
        $this->addSql('ALTER TABLE bid ADD CONSTRAINT FK_BID_VISIT_REQUEST_ID FOREIGN KEY (visit_request_id) REFERENCES visit_request (id)');
        $this->addSql('CREATE INDEX IDX_BID_VISIT_REQUEST_ID ON bid (visit_request_id)');
    }
}

