<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Garantiza como máximo un CalendarEvent por (request, professional):
 * elimina duplicados (conserva el más reciente por updated_at, id) y crea el UNIQUE si falta.
 */
final class Version20260730140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CalendarEvent: dedupe by request+professional and ensure unique index';
    }

    public function up(Schema $schema): void
    {
        $duplicates = $this->connection->fetchAllAssociative(
            'SELECT request_id, professional_id, COUNT(*) AS cnt
             FROM calendar_event
             GROUP BY request_id, professional_id
             HAVING cnt > 1'
        );

        foreach ($duplicates as $row) {
            $ids = $this->connection->fetchFirstColumn(
                'SELECT id FROM calendar_event
                 WHERE request_id = ? AND professional_id = ?
                 ORDER BY updated_at DESC, id DESC',
                [(int) $row['request_id'], (int) $row['professional_id']]
            );
            if ($ids === []) {
                continue;
            }
            // Conservar el primero (más reciente); borrar el resto.
            array_shift($ids);
            if ($ids === []) {
                continue;
            }
            $this->connection->executeStatement(
                'DELETE FROM calendar_event WHERE id IN (?)',
                [$ids],
                [ArrayParameterType::INTEGER]
            );
        }

        $indexes = $this->connection->createSchemaManager()->listTableIndexes('calendar_event');
        if (!isset($indexes['uniq_calendar_event_request_professional'])) {
            $this->addSql(
                'CREATE UNIQUE INDEX uniq_calendar_event_request_professional ON calendar_event (request_id, professional_id)'
            );
        }
    }

    public function down(Schema $schema): void
    {
        // No recreamos duplicados; solo quitamos el índice si se pide rollback.
        $indexes = $this->connection->createSchemaManager()->listTableIndexes('calendar_event');
        if (isset($indexes['uniq_calendar_event_request_professional'])) {
            $this->addSql('DROP INDEX uniq_calendar_event_request_professional ON calendar_event');
        }
    }
}
