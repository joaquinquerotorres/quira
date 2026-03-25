<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260304123000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add visit_request table and origin/visit_request to bid';
    }

    public function up(Schema $schema): void
    {
        // VisitRequest table
        $this->addSql('CREATE TABLE visit_request (id INT AUTO_INCREMENT NOT NULL, request_id INT NOT NULL, professional_id INT NOT NULL, status VARCHAR(20) NOT NULL, note LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX IDX_VISIT_REQUEST_REQUEST_ID (request_id), INDEX IDX_VISIT_REQUEST_PROFESSIONAL_ID (professional_id), UNIQUE INDEX uniq_visit_request_request_professional (request_id, professional_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE visit_request ADD CONSTRAINT FK_VISIT_REQUEST_REQUEST_ID FOREIGN KEY (request_id) REFERENCES `request` (id)');
        $this->addSql('ALTER TABLE visit_request ADD CONSTRAINT FK_VISIT_REQUEST_PROFESSIONAL_ID FOREIGN KEY (professional_id) REFERENCES professional_profile (id)');

        // Bid extensions
        $this->addSql("ALTER TABLE bid ADD origin VARCHAR(20) DEFAULT 'APP' NOT NULL, ADD visit_request_id INT DEFAULT NULL");
        $this->addSql('ALTER TABLE bid ADD CONSTRAINT FK_BID_VISIT_REQUEST_ID FOREIGN KEY (visit_request_id) REFERENCES visit_request (id)');
        $this->addSql('CREATE INDEX IDX_BID_VISIT_REQUEST_ID ON bid (visit_request_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bid DROP FOREIGN KEY FK_BID_VISIT_REQUEST_ID');
        $this->addSql('DROP INDEX IDX_BID_VISIT_REQUEST_ID ON bid');
        $this->addSql('ALTER TABLE bid DROP origin, DROP visit_request_id');

        $this->addSql('ALTER TABLE visit_request DROP FOREIGN KEY FK_VISIT_REQUEST_REQUEST_ID');
        $this->addSql('ALTER TABLE visit_request DROP FOREIGN KEY FK_VISIT_REQUEST_PROFESSIONAL_ID');
        $this->addSql('DROP TABLE visit_request');
    }
}

