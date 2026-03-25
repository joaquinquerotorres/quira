<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260305123000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add estimated_execution_time to bid';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE bid ADD estimated_execution_time VARCHAR(50) DEFAULT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bid DROP estimated_execution_time');
    }
}

