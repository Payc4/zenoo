<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** "Send a photo from your phone": one-off QR links (App\Entity\PhotoHandoff). */
final class Version20261120170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'photo_handoff';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('CREATE TABLE photo_handoff (id INT AUTO_INCREMENT NOT NULL, token_hash VARCHAR(64) NOT NULL, owner VARCHAR(80) NOT NULL, max_files INT NOT NULL, locale VARCHAR(5) NOT NULL, files JSON NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', expires_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX UNIQ_PHOTO_HANDOFF_TOKEN (token_hash), INDEX IDX_PHOTO_HANDOFF_EXPIRES (expires_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        } elseif ($platform instanceof SqlitePlatform) {
            $this->addSql('CREATE TABLE photo_handoff (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, token_hash VARCHAR(64) NOT NULL, owner VARCHAR(80) NOT NULL, max_files INTEGER NOT NULL, locale VARCHAR(5) NOT NULL, files CLOB NOT NULL, created_at DATETIME NOT NULL, expires_at DATETIME NOT NULL)');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_PHOTO_HANDOFF_TOKEN ON photo_handoff (token_hash)');
            $this->addSql('CREATE INDEX IDX_PHOTO_HANDOFF_EXPIRES ON photo_handoff (expires_at)');
        } else {
            $this->abortIf(true, 'This migration supports MySQL (production) and SQLite (development) only.');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE photo_handoff');
    }
}
