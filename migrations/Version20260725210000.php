<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260725210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CalendarEvent: replace scheduled_on date with starts_at datetime (no ends_at)';
    }

    public function up(Schema $schema): void
    {
        $columns = $this->connection->createSchemaManager()->listTableColumns('calendar_event');
        $indexes = $this->connection->createSchemaManager()->listTableIndexes('calendar_event');

        if (!isset($columns['starts_at'])) {
            $this->addSql('ALTER TABLE calendar_event ADD starts_at DATETIME DEFAULT NULL');
        }

        if (isset($columns['scheduled_on'])) {
            $this->addSql('UPDATE calendar_event SET starts_at = TIMESTAMP(scheduled_on) WHERE starts_at IS NULL AND scheduled_on IS NOT NULL');
        }

        $this->addSql('ALTER TABLE calendar_event MODIFY starts_at DATETIME NOT NULL');

        if (isset($indexes['idx_calendar_event_pro_date'])) {
            $this->addSql('DROP INDEX idx_calendar_event_pro_date ON calendar_event');
        }

        if (isset($columns['scheduled_on'])) {
            $this->addSql('ALTER TABLE calendar_event DROP scheduled_on');
        }

        if (isset($columns['ends_at'])) {
            $this->addSql('ALTER TABLE calendar_event DROP ends_at');
        }

        if (!isset($indexes['idx_calendar_event_pro_starts'])) {
            $this->addSql('CREATE INDEX idx_calendar_event_pro_starts ON calendar_event (professional_id, starts_at)');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_calendar_event_pro_starts ON calendar_event');
        $this->addSql('ALTER TABLE calendar_event ADD scheduled_on DATE DEFAULT NULL');
        $this->addSql('UPDATE calendar_event SET scheduled_on = DATE(starts_at)');
        $this->addSql('ALTER TABLE calendar_event MODIFY scheduled_on DATE NOT NULL');
        $this->addSql('CREATE INDEX idx_calendar_event_pro_date ON calendar_event (professional_id, scheduled_on)');
        $this->addSql('ALTER TABLE calendar_event DROP starts_at');
    }
}
