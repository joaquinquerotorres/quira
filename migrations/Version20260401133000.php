<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260401133000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Request: elimina solicitudes legacy CANCELLED y sus dependencias';
    }

    public function up(Schema $schema): void
    {
        // Limpieza de datos legacy al retirar CANCELLED del dominio de Request.
        $this->addSql("DELETE FROM review WHERE request_id IN (SELECT id FROM request WHERE status = 'CANCELLED')");
        $this->addSql("DELETE FROM visit_request WHERE request_id IN (SELECT id FROM request WHERE status = 'CANCELLED')");
        $this->addSql("DELETE FROM bid WHERE request_id IN (SELECT id FROM request WHERE status = 'CANCELLED')");
        $this->addSql("DELETE FROM request_question WHERE request_id IN (SELECT id FROM request WHERE status = 'CANCELLED')");
        $this->addSql("DELETE FROM request WHERE status = 'CANCELLED'");
    }

    public function down(Schema $schema): void
    {
        // Irreversible: no se puede reconstruir las requests borradas.
    }
}

