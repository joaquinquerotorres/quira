<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260725120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add unique constraint on bid (request_id, professional_id)';
    }

    public function up(Schema $schema): void
    {
        // Conservar la bid más antigua por (request, professional) y eliminar duplicados.
        $this->addSql(<<<'SQL'
            DELETE b1 FROM bid b1
            INNER JOIN bid b2
              ON b1.request_id = b2.request_id
             AND b1.professional_id = b2.professional_id
             AND b1.id > b2.id
        SQL);

        $this->addSql('CREATE UNIQUE INDEX uniq_bid_request_professional ON bid (request_id, professional_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_bid_request_professional ON bid');
    }
}
