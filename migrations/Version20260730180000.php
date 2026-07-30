<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260730180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add professional_profile.created_at (member since / En Quira desde)';
    }

    public function up(Schema $schema): void
    {
        $columns = $this->connection->createSchemaManager()->listTableColumns('professional_profile');
        if (isset($columns['created_at'])) {
            return;
        }

        // Nullable primero para filas existentes; luego backfill y NOT NULL.
        $this->addSql('ALTER TABLE professional_profile ADD created_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('UPDATE professional_profile SET created_at = CURRENT_TIMESTAMP WHERE created_at IS NULL');
        $this->addSql('ALTER TABLE professional_profile MODIFY created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $columns = $this->connection->createSchemaManager()->listTableColumns('professional_profile');
        if (!isset($columns['created_at'])) {
            return;
        }
        $this->addSql('ALTER TABLE professional_profile DROP created_at');
    }
}
