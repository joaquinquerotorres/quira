<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260227150558 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial schema: user, client_profile, professional_profile, request, bid, review, request_question, notification, gemini_cache, refresh_tokens, messenger_messages';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE bid (id INT AUTO_INCREMENT NOT NULL, price_quote INT NOT NULL, comment LONGTEXT DEFAULT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, request_id INT NOT NULL, professional_id INT NOT NULL, INDEX IDX_4AF2B3F3427EB8A5 (request_id), INDEX IDX_4AF2B3F3DB77003 (professional_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE client_profile (id INT AUTO_INCREMENT NOT NULL, full_name VARCHAR(255) NOT NULL, phone_number VARCHAR(20) DEFAULT NULL, avatar VARCHAR(255) DEFAULT NULL, rating_as_client DOUBLE PRECISION DEFAULT NULL, review_count INT DEFAULT 0 NOT NULL, notify_request_activity TINYINT DEFAULT 1 NOT NULL, notify_bid_activity TINYINT DEFAULT 1 NOT NULL, notify_reviews TINYINT DEFAULT 1 NOT NULL, user_id INT NOT NULL, UNIQUE INDEX UNIQ_D36AEE72A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE gemini_cache (id INT AUTO_INCREMENT NOT NULL, cache_id VARCHAR(255) NOT NULL, expires_at DATETIME NOT NULL, model VARCHAR(50) NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE notification (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, message LONGTEXT NOT NULL, type VARCHAR(50) NOT NULL, is_read TINYINT DEFAULT 0 NOT NULL, related_id INT DEFAULT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX IDX_BF5476CAA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE professional_profile (id INT AUTO_INCREMENT NOT NULL, full_name VARCHAR(255) NOT NULL, tax_id VARCHAR(50) DEFAULT NULL, bio LONGTEXT DEFAULT NULL, phone_number VARCHAR(20) DEFAULT NULL, avatar VARCHAR(255) DEFAULT NULL, address VARCHAR(255) DEFAULT NULL, location_point POINT DEFAULT NULL, service_radius_km INT DEFAULT 30 NOT NULL, skills JSON NOT NULL, is_verified TINYINT DEFAULT 0 NOT NULL, trial_ends_at DATETIME DEFAULT NULL, rating DOUBLE PRECISION DEFAULT NULL, review_count INT DEFAULT 0 NOT NULL, notify_request_activity TINYINT DEFAULT 1 NOT NULL, notify_bid_activity TINYINT DEFAULT 1 NOT NULL, notify_reviews TINYINT DEFAULT 1 NOT NULL, user_id INT NOT NULL, UNIQUE INDEX UNIQ_E728A82A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE refresh_tokens (refresh_token VARCHAR(128) NOT NULL, username VARCHAR(255) NOT NULL, valid DATETIME NOT NULL, id INT AUTO_INCREMENT NOT NULL, UNIQUE INDEX UNIQ_9BACE7E1C74F2195 (refresh_token), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE request (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, price_amount INT DEFAULT NULL, ai_diagnosis JSON DEFAULT NULL, status VARCHAR(50) NOT NULL, risk_level VARCHAR(50) NOT NULL, category VARCHAR(50) NOT NULL, address VARCHAR(255) NOT NULL, precise_address VARCHAR(255) DEFAULT NULL, location_point POINT DEFAULT NULL, scheduled_at DATE DEFAULT NULL, photo_url VARCHAR(255) DEFAULT NULL, audio_url VARCHAR(255) DEFAULT NULL, video_url VARCHAR(255) DEFAULT NULL, is_flagged TINYINT DEFAULT 0 NOT NULL, moderation_reason LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, client_id INT NOT NULL, assigned_professional_id INT DEFAULT NULL, INDEX IDX_3B978F9F19EB6921 (client_id), INDEX IDX_3B978F9F2FB36A3D (assigned_professional_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE request_question (id INT AUTO_INCREMENT NOT NULL, question_text LONGTEXT NOT NULL, answer_text LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, request_id INT NOT NULL, author_id INT NOT NULL, INDEX IDX_43D2A96B427EB8A5 (request_id), INDEX IDX_43D2A96BF675F31B (author_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE review (id INT AUTO_INCREMENT NOT NULL, score INT NOT NULL, comment LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, request_id INT NOT NULL, author_id INT NOT NULL, target_id INT NOT NULL, INDEX IDX_794381C6427EB8A5 (request_id), INDEX IDX_794381C6F675F31B (author_id), INDEX IDX_794381C6158E0B66 (target_id), UNIQUE INDEX unique_review_per_job (request_id, author_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `user` (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, fcm_token VARCHAR(255) DEFAULT NULL, firebase_uid VARCHAR(255) DEFAULT NULL, verified_email TINYINT DEFAULT 0 NOT NULL, verified_phone TINYINT DEFAULT 0 NOT NULL, UNIQUE INDEX UNIQ_8D93D649E7927C74 (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE bid ADD CONSTRAINT FK_4AF2B3F3427EB8A5 FOREIGN KEY (request_id) REFERENCES request (id)');
        $this->addSql('ALTER TABLE bid ADD CONSTRAINT FK_4AF2B3F3DB77003 FOREIGN KEY (professional_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE client_profile ADD CONSTRAINT FK_D36AEE72A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CAA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE professional_profile ADD CONSTRAINT FK_E728A82A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE request ADD CONSTRAINT FK_3B978F9F19EB6921 FOREIGN KEY (client_id) REFERENCES client_profile (id)');
        $this->addSql('ALTER TABLE request ADD CONSTRAINT FK_3B978F9F2FB36A3D FOREIGN KEY (assigned_professional_id) REFERENCES professional_profile (id)');
        $this->addSql('ALTER TABLE request_question ADD CONSTRAINT FK_43D2A96B427EB8A5 FOREIGN KEY (request_id) REFERENCES request (id)');
        $this->addSql('ALTER TABLE request_question ADD CONSTRAINT FK_43D2A96BF675F31B FOREIGN KEY (author_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE review ADD CONSTRAINT FK_794381C6427EB8A5 FOREIGN KEY (request_id) REFERENCES request (id)');
        $this->addSql('ALTER TABLE review ADD CONSTRAINT FK_794381C6F675F31B FOREIGN KEY (author_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE review ADD CONSTRAINT FK_794381C6158E0B66 FOREIGN KEY (target_id) REFERENCES `user` (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE bid DROP FOREIGN KEY FK_4AF2B3F3427EB8A5');
        $this->addSql('ALTER TABLE bid DROP FOREIGN KEY FK_4AF2B3F3DB77003');
        $this->addSql('ALTER TABLE client_profile DROP FOREIGN KEY FK_D36AEE72A76ED395');
        $this->addSql('ALTER TABLE notification DROP FOREIGN KEY FK_BF5476CAA76ED395');
        $this->addSql('ALTER TABLE professional_profile DROP FOREIGN KEY FK_E728A82A76ED395');
        $this->addSql('ALTER TABLE request DROP FOREIGN KEY FK_3B978F9F19EB6921');
        $this->addSql('ALTER TABLE request DROP FOREIGN KEY FK_3B978F9F2FB36A3D');
        $this->addSql('ALTER TABLE request_question DROP FOREIGN KEY FK_43D2A96B427EB8A5');
        $this->addSql('ALTER TABLE request_question DROP FOREIGN KEY FK_43D2A96BF675F31B');
        $this->addSql('ALTER TABLE review DROP FOREIGN KEY FK_794381C6427EB8A5');
        $this->addSql('ALTER TABLE review DROP FOREIGN KEY FK_794381C6F675F31B');
        $this->addSql('ALTER TABLE review DROP FOREIGN KEY FK_794381C6158E0B66');
        $this->addSql('DROP TABLE bid');
        $this->addSql('DROP TABLE client_profile');
        $this->addSql('DROP TABLE gemini_cache');
        $this->addSql('DROP TABLE notification');
        $this->addSql('DROP TABLE professional_profile');
        $this->addSql('DROP TABLE refresh_tokens');
        $this->addSql('DROP TABLE request');
        $this->addSql('DROP TABLE request_question');
        $this->addSql('DROP TABLE review');
        $this->addSql('DROP TABLE `user`');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
