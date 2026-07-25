<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260725203000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CalendarEvent: replace starts_at/ends_at with single scheduled_on date';
    }

    public function up(Schema $schema): void
    {
        $columns = $this->connection->createSchemaManager()->listTableColumns('calendar_event');
        $indexes = $this->connection->createSchemaManager()->listTableIndexes('calendar_event');

        if (!isset($columns['scheduled_on'])) {
            $this->addSql('ALTER TABLE calendar_event ADD scheduled_on DATE DEFAULT NULL');
        }

        if (isset($columns['starts_at'])) {
            $this->addSql('UPDATE calendar_event SET scheduled_on = DATE(starts_at) WHERE scheduled_on IS NULL AND starts_at IS NOT NULL');
        }

        $this->addSql('ALTER TABLE calendar_event MODIFY scheduled_on DATE NOT NULL');

        if (!isset($indexes['idx_calendar_event_professional'])) {
            $this->addSql('CREATE INDEX idx_calendar_event_professional ON calendar_event (professional_id)');
        }

        if (isset($indexes['idx_calendar_event_pro_starts'])) {
            $this->addSql('DROP INDEX idx_calendar_event_pro_starts ON calendar_event');
        }

        if (isset($columns['starts_at']) || isset($columns['ends_at'])) {
            $drops = [];
            if (isset($columns['starts_at'])) {
                $drops[] = 'DROP starts_at';
            }
            if (isset($columns['ends_at'])) {
                $drops[] = 'DROP ends_at';
            }
            $this->addSql('ALTER TABLE calendar_event ' . implode(', ', $drops));
        }

        if (!isset($indexes['idx_calendar_event_pro_date'])) {
            $this->addSql('CREATE INDEX idx_calendar_event_pro_date ON calendar_event (professional_id, scheduled_on)');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_calendar_event_pro_date ON calendar_event');
        $this->addSql('ALTER TABLE calendar_event ADD starts_at DATETIME DEFAULT NULL, ADD ends_at DATETIME DEFAULT NULL');
        $this->addSql('UPDATE calendar_event SET starts_at = TIMESTAMP(scheduled_on), ends_at = TIMESTAMP(DATE_ADD(scheduled_on, INTERVAL 2 HOUR))');
        $this->addSql('ALTER TABLE calendar_event MODIFY starts_at DATETIME NOT NULL, MODIFY ends_at DATETIME NOT NULL');
        $this->addSql('CREATE INDEX idx_calendar_event_pro_starts ON calendar_event (professional_id, starts_at)');
        $this->addSql('DROP INDEX idx_calendar_event_professional ON calendar_event');
        $this->addSql('ALTER TABLE calendar_event DROP scheduled_on');
    }
}
