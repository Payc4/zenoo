<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Photos and files sent in the coach chat (see App\Chat\ChatFiles). */
final class Version20261113150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Chat message attachments';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE chat_message ADD attachments JSON DEFAULT NULL');
        } elseif ($platform instanceof SqlitePlatform) {
            $this->addSql('ALTER TABLE chat_message ADD COLUMN attachments CLOB DEFAULT NULL');
        } else {
            $this->abortIf(true, 'This migration supports MySQL (production) and SQLite (development) only.');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE chat_message DROP COLUMN attachments');
    }
}
