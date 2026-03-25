<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260305124500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add answer_media_urls JSON column to request_question for up to 3 media attachments';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE request_question ADD answer_media_urls JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE request_question DROP answer_media_urls');
    }
}

