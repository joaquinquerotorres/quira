<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260304120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add extra media arrays to request (extraPhotoUrls, extraAudioUrls, extraVideoUrls)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE request ADD extra_photo_urls JSON DEFAULT NULL, ADD extra_audio_urls JSON DEFAULT NULL, ADD extra_video_urls JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE request DROP extra_photo_urls, DROP extra_audio_urls, DROP extra_video_urls');
    }
}

