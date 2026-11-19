<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Roadmap 04: whether an account gets the tips-and-offers email series (opt-in from the quiz). */
final class Version20261119140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'user.marketing_emails';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE `user` ADD marketing_emails TINYINT(1) DEFAULT 0 NOT NULL');
        } elseif ($platform instanceof SqlitePlatform) {
            $this->addSql('ALTER TABLE "user" ADD COLUMN marketing_emails BOOLEAN DEFAULT 0 NOT NULL');
        } else {
            $this->abortIf(true, 'This migration supports MySQL (production) and SQLite (development) only.');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP COLUMN marketing_emails');
    }
}
