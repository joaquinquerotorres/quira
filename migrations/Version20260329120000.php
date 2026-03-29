<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260329120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Request: client_original_description (texto previo a IA, alineado con /predict)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE request ADD client_original_description LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE request DROP client_original_description');
    }
}
