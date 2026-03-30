<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260330122000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ProfessionalProfile: añade verified_tax_id (CIF verificado) para distinguir PRO vs FREE/SOLVER';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE professional_profile ADD verified_tax_id TINYINT(1) NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE professional_profile DROP verified_tax_id');
    }
}

