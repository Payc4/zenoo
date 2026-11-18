<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Measurement: where each account came from, and daily funnel counts (no personal data). */
final class Version20261119000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'user.attribution; funnel_event';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE `user` ADD attribution JSON DEFAULT NULL');
            $this->addSql('CREATE TABLE funnel_event (id INT AUTO_INCREMENT NOT NULL, day DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\', name VARCHAR(60) NOT NULL, source VARCHAR(80) NOT NULL, count INT NOT NULL, UNIQUE INDEX UNIQ_FUNNEL_DAY_NAME_SOURCE (day, name, source), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        } elseif ($platform instanceof SqlitePlatform) {
            $this->addSql('ALTER TABLE "user" ADD COLUMN attribution CLOB DEFAULT NULL');
            $this->addSql('CREATE TABLE funnel_event (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, day DATE NOT NULL, name VARCHAR(60) NOT NULL, source VARCHAR(80) NOT NULL, count INTEGER NOT NULL)');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_FUNNEL_DAY_NAME_SOURCE ON funnel_event (day, name, source)');
        } else {
            $this->abortIf(true, 'This migration supports MySQL (production) and SQLite (development) only.');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP COLUMN attribution');
        $this->addSql('DROP TABLE funnel_event');
    }
}
