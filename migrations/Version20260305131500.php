<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260305131500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop scheduled_at column from request (replaced by desired_execution_time)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE request DROP scheduled_at');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE request ADD scheduled_at DATE DEFAULT NULL');
    }
}

