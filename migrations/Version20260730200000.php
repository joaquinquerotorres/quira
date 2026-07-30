<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Índices espaciales (market geo / findMatchingPros) y compuestos habituales.
 * Idempotente y reversible en MySQL 8.
 */
final class Version20260730200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Spatial + composite indexes for request/professional_profile/review list & geo queries';
    }

    public function up(Schema $schema): void
    {
        $this->createIndexIfMissing(
            'request',
            'idx_request_location_point',
            'CREATE SPATIAL INDEX idx_request_location_point ON request (location_point)'
        );
        $this->createIndexIfMissing(
            'professional_profile',
            'idx_professional_profile_location_point',
            'CREATE SPATIAL INDEX idx_professional_profile_location_point ON professional_profile (location_point)'
        );
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
        $this->dropIndexIfExists('request', 'idx_request_location_point');
        $this->dropIndexIfExists('professional_profile', 'idx_professional_profile_location_point');
        $this->dropIndexIfExists('request', 'idx_request_status_category_created');
        $this->dropIndexIfExists('review', 'idx_review_target_created');
        $this->dropIndexIfExists('review', 'idx_review_author_created');
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
