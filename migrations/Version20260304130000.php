<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260304130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename client_profile.rating_as_client to rating for unified naming';
    }

    public function up(Schema $schema): void
    {
        // Renombrar columna rating_as_client -> rating en client_profile si existe.
        $table = $schema->getTable('client_profile');
        if ($table->hasColumn('rating_as_client') && !$table->hasColumn('rating')) {
            $this->addSql('ALTER TABLE client_profile CHANGE rating_as_client rating DOUBLE PRECISION DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('client_profile');
        if ($table->hasColumn('rating') && !$table->hasColumn('rating_as_client')) {
            $this->addSql('ALTER TABLE client_profile CHANGE rating rating_as_client DOUBLE PRECISION DEFAULT NULL');
        }
    }
}

