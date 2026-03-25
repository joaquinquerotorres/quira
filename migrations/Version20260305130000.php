<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260305130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add desired_execution_time to request (discrete scheduling options)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE request ADD desired_execution_time VARCHAR(50) DEFAULT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE request DROP desired_execution_time');
    }
}

