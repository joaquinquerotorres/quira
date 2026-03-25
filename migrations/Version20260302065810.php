<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260302065810 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename trial_ends_at to paid_through_at in professional_profile';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE professional_profile CHANGE trial_ends_at paid_through_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE professional_profile CHANGE paid_through_at trial_ends_at DATETIME DEFAULT NULL');
    }
}
