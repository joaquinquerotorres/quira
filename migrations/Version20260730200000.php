<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Índices compuestos para market/reviews.
 *
 * SPATIAL INDEX sobre location_point NO se crea: en MySQL/MariaDB actuales
 * (error 1252) todas las partes de un índice espacial deben ser NOT NULL, y
 * request.location_point / professional_profile.location_point son nullable
 * a propósito (requests/perfiles sin geo). Forzar NOT NULL o un POINT(0,0)
 * sentinela rompería el matching geo. Cuando el producto exija ubicación
 * siempre, se podrá ALTER … NOT NULL + CREATE SPATIAL INDEX.
 */
final class Version20260730200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Composite indexes for request market filters and review lookups (spatial skipped: nullable POINT → MySQL 1252)';
    }

    public function up(Schema $schema): void
    {
        // Por si una ejecución previa dejó el migration a medias: no intentamos SPATIAL.
        $this->dropIndexIfExists('request', 'idx_request_location_point');
        $this->dropIndexIfExists('professional_profile', 'idx_professional_profile_location_point');

        $this->createIndexIfMissing(
            'request',
            'idx_request_status_category_created',
            'CREATE INDEX idx_request_status_category_created ON request (status, category, created_at)'
        );
        $this->createIndexIfMissing(
            'review',
            'idx_review_target_created',
            'CREATE INDEX idx_review_target_created ON review (target_id, created_at)'
        );
        $this->createIndexIfMissing(
            'review',
            'idx_review_author_created',
            'CREATE INDEX idx_review_author_created ON review (author_id, created_at)'
        );
    }

    public function down(Schema $schema): void
    {
        $this->dropIndexIfExists('request', 'idx_request_status_category_created');
        $this->dropIndexIfExists('review', 'idx_review_target_created');
        $this->dropIndexIfExists('review', 'idx_review_author_created');
        $this->dropIndexIfExists('request', 'idx_request_location_point');
        $this->dropIndexIfExists('professional_profile', 'idx_professional_profile_location_point');
    }

    private function createIndexIfMissing(string $table, string $indexName, string $sql): void
    {
        if ($this->indexExists($table, $indexName)) {
            return;
        }
        $this->addSql($sql);
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        if (!$this->indexExists($table, $indexName)) {
            return;
        }
        $this->addSql(sprintf('DROP INDEX %s ON %s', $indexName, $table));
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $indexes = $this->connection->createSchemaManager()->listTableIndexes($table);

        return isset($indexes[$indexName]) || isset($indexes[strtolower($indexName)]);
    }
}
