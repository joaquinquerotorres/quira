<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260303220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Move phone verification from user to client/professional profiles';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE client_profile ADD verified_phone TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE professional_profile ADD verified_phone TINYINT(1) DEFAULT 0 NOT NULL');

        // Migrar estado desde user.verified_phone si existe esa columna.
        $this->addSql('
            UPDATE client_profile c
            JOIN user u ON c.user_id = u.id
            SET c.verified_phone = u.verified_phone
        ');
        $this->addSql('
            UPDATE professional_profile p
            JOIN user u ON p.user_id = u.id
            SET p.verified_phone = u.verified_phone
        ');

        // Eliminar columna antigua en user si existe.
        $this->addSql('ALTER TABLE user DROP verified_phone');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD verified_phone TINYINT(1) DEFAULT 0 NOT NULL');

        $this->addSql('
            UPDATE user u
            LEFT JOIN client_profile c ON c.user_id = u.id
            LEFT JOIN professional_profile p ON p.user_id = u.id
            SET u.verified_phone = (COALESCE(c.verified_phone, 0) = 1 OR COALESCE(p.verified_phone, 0) = 1)
        ');

        $this->addSql('ALTER TABLE client_profile DROP verified_phone');
        $this->addSql('ALTER TABLE professional_profile DROP verified_phone');
    }
}

