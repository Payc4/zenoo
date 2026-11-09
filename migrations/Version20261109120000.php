<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Chat check-ins: answers on the dog profile, and when they were last asked. */
final class Version20261109120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Dog profile check-in answers';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql("ALTER TABLE dog_profile ADD checkins JSON DEFAULT NULL, ADD checkin_asked_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
        } elseif ($platform instanceof SqlitePlatform) {
            $this->addSql('ALTER TABLE dog_profile ADD COLUMN checkins CLOB DEFAULT NULL');
            $this->addSql('ALTER TABLE dog_profile ADD COLUMN checkin_asked_at DATETIME DEFAULT NULL');
        } else {
            $this->abortIf(true, 'This migration supports MySQL (production) and SQLite (development) only.');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dog_profile DROP COLUMN checkins');
        $this->addSql('ALTER TABLE dog_profile DROP COLUMN checkin_asked_at');
    }
}
