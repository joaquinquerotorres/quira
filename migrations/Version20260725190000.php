<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260725190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add calendar_event for scheduled work linked to request and professional';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE calendar_event (
            id INT AUTO_INCREMENT NOT NULL,
            request_id INT NOT NULL,
            professional_id INT NOT NULL,
            starts_at DATETIME NOT NULL,
            ends_at DATETIME NOT NULL,
            notes LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX idx_calendar_event_pro_starts (professional_id, starts_at),
            UNIQUE INDEX uniq_calendar_event_request_professional (request_id, professional_id),
            INDEX IDX_CAL_EVT_REQUEST (request_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE calendar_event ADD CONSTRAINT FK_CAL_EVT_REQUEST FOREIGN KEY (request_id) REFERENCES request (id)');
        $this->addSql('ALTER TABLE calendar_event ADD CONSTRAINT FK_CAL_EVT_PRO FOREIGN KEY (professional_id) REFERENCES professional_profile (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE calendar_event DROP FOREIGN KEY FK_CAL_EVT_REQUEST');
        $this->addSql('ALTER TABLE calendar_event DROP FOREIGN KEY FK_CAL_EVT_PRO');
        $this->addSql('DROP TABLE calendar_event');
    }
}
