<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Coach safety and review marks on chat messages; coach follow-up email setting for users. */
final class Version20261118200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Chat message flag and review note; user coach_emails';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE chat_message ADD flag VARCHAR(40) DEFAULT NULL, ADD review VARCHAR(500) DEFAULT NULL');
            $this->addSql('ALTER TABLE `user` ADD coach_emails TINYINT(1) DEFAULT 1 NOT NULL');
        } elseif ($platform instanceof SqlitePlatform) {
            $this->addSql('ALTER TABLE chat_message ADD COLUMN flag VARCHAR(40) DEFAULT NULL');
            $this->addSql('ALTER TABLE chat_message ADD COLUMN review VARCHAR(500) DEFAULT NULL');
            $this->addSql('ALTER TABLE "user" ADD COLUMN coach_emails BOOLEAN DEFAULT 1 NOT NULL');
        } else {
            $this->abortIf(true, 'This migration supports MySQL (production) and SQLite (development) only.');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE chat_message DROP COLUMN flag');
        $this->addSql('ALTER TABLE chat_message DROP COLUMN review');
        $this->addSql('ALTER TABLE `user` DROP COLUMN coach_emails');
    }
}
