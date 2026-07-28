<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260727210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create predict_task for async AI analysis by media URL';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE predict_task (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT NOT NULL,
            public_id VARCHAR(36) NOT NULL,
            status VARCHAR(20) NOT NULL,
            description LONGTEXT DEFAULT NULL,
            image_url VARCHAR(2048) DEFAULT NULL,
            audio_url VARCHAR(2048) DEFAULT NULL,
            video_url VARCHAR(2048) DEFAULT NULL,
            location VARCHAR(255) DEFAULT NULL,
            result JSON DEFAULT NULL,
            error_message LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX UNIQ_PREDICT_TASK_PUBLIC_ID (public_id),
            INDEX idx_predict_task_public_id (public_id),
            INDEX idx_predict_task_user_status (user_id, status),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE predict_task ADD CONSTRAINT FK_PREDICT_TASK_USER FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE predict_task DROP FOREIGN KEY FK_PREDICT_TASK_USER');
        $this->addSql('DROP TABLE predict_task');
    }
}
