<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Timestamps para métricas admin: user.created_at, bid/request.updated_at + índices.
 */
final class Version20260731140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Admin stats: user.created_at, bid.updated_at, request.updated_at and indexes';
    }

    public function up(Schema $schema): void
    {
        $sm = $this->connection->createSchemaManager();

        $userCols = $sm->listTableColumns('user');
        if (!isset($userCols['created_at'])) {
            $this->addSql('ALTER TABLE `user` ADD created_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
            $this->addSql('UPDATE `user` SET created_at = CURRENT_TIMESTAMP WHERE created_at IS NULL');
            $this->addSql('ALTER TABLE `user` MODIFY created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        }

        $bidCols = $sm->listTableColumns('bid');
        if (!isset($bidCols['updated_at'])) {
            $this->addSql('ALTER TABLE bid ADD updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
            $this->addSql('UPDATE bid SET updated_at = created_at WHERE updated_at IS NULL');
            $this->addSql('ALTER TABLE bid MODIFY updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        }

        $requestCols = $sm->listTableColumns('request');
        if (!isset($requestCols['updated_at'])) {
            $this->addSql('ALTER TABLE request ADD updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
            $this->addSql('UPDATE request SET updated_at = created_at WHERE updated_at IS NULL');
            $this->addSql('ALTER TABLE request MODIFY updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        }

        $this->createIndexIfMissing('user', 'idx_user_created_at', 'CREATE INDEX idx_user_created_at ON `user` (created_at)');
        $this->createIndexIfMissing('bid', 'idx_bid_status_updated_at', 'CREATE INDEX idx_bid_status_updated_at ON bid (status, updated_at)');
        $this->createIndexIfMissing('request', 'idx_request_status_updated_at', 'CREATE INDEX idx_request_status_updated_at ON request (status, updated_at)');
        $this->createIndexIfMissing('professional_profile', 'idx_pro_paid_through_at', 'CREATE INDEX idx_pro_paid_through_at ON professional_profile (paid_through_at)');
        $this->createIndexIfMissing('professional_profile', 'idx_pro_cancel_at_period_end', 'CREATE INDEX idx_pro_cancel_at_period_end ON professional_profile (subscription_cancel_at_period_end)');
        $this->createIndexIfMissing('professional_profile', 'idx_pro_created_at', 'CREATE INDEX idx_pro_created_at ON professional_profile (created_at)');
        $this->createIndexIfMissing('review', 'idx_review_created_at', 'CREATE INDEX idx_review_created_at ON review (created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->dropIndexIfExists('user', 'idx_user_created_at');
        $this->dropIndexIfExists('bid', 'idx_bid_status_updated_at');
        $this->dropIndexIfExists('request', 'idx_request_status_updated_at');
        $this->dropIndexIfExists('professional_profile', 'idx_pro_paid_through_at');
        $this->dropIndexIfExists('professional_profile', 'idx_pro_cancel_at_period_end');
        $this->dropIndexIfExists('professional_profile', 'idx_pro_created_at');
        $this->dropIndexIfExists('review', 'idx_review_created_at');

        $sm = $this->connection->createSchemaManager();
        if (isset($sm->listTableColumns('user')['created_at'])) {
            $this->addSql('ALTER TABLE `user` DROP created_at');
        }
        if (isset($sm->listTableColumns('bid')['updated_at'])) {
            $this->addSql('ALTER TABLE bid DROP updated_at');
        }
        if (isset($sm->listTableColumns('request')['updated_at'])) {
            $this->addSql('ALTER TABLE request DROP updated_at');
        }
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
        $quotedTable = $table === 'user' ? '`user`' : $table;
        $this->addSql(sprintf('DROP INDEX %s ON %s', $indexName, $quotedTable));
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $indexes = $this->connection->createSchemaManager()->listTableIndexes($table);

        return isset($indexes[$indexName]) || isset($indexes[strtolower($indexName)]);
    }
}
