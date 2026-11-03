<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Chat question cards: an assistant message can carry the cards it showed.
 */
final class Version20261103180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Question cards on chat messages';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE chat_message ADD questions JSON DEFAULT NULL');
        } elseif ($platform instanceof SqlitePlatform) {
            // SQLite has no JSON type: Doctrine recognises the column by its "--(DC2Type:json)"
            // comment, which only a table rebuild can add. This is Doctrine's own rebuild.
            foreach (self::SQLITE_REBUILD as $sql) {
                $this->addSql($sql);
            }
        } else {
            $this->abortIf(true, 'This migration supports MySQL (production) and SQLite (development) only.');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE chat_message DROP COLUMN questions');
    }

    private const SQLITE_REBUILD = [
        "CREATE TEMPORARY TABLE __temp__chat_message AS SELECT id, conversation_id, user_id, role, content, input_tokens, output_tokens, created_at FROM chat_message",
        "DROP TABLE chat_message",
        "CREATE TABLE chat_message (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, conversation_id INTEGER NOT NULL, user_id INTEGER NOT NULL, role VARCHAR(16) NOT NULL, content CLOB NOT NULL, input_tokens INTEGER DEFAULT NULL, output_tokens INTEGER DEFAULT NULL, created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)\n, questions CLOB DEFAULT NULL --(DC2Type:json)\n, CONSTRAINT FK_FAB3FC169AC0396 FOREIGN KEY (conversation_id) REFERENCES chat_conversation (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_FAB3FC16A76ED395 FOREIGN KEY (user_id) REFERENCES \"user\" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)",
        "INSERT INTO chat_message (id, conversation_id, user_id, role, content, input_tokens, output_tokens, created_at) SELECT id, conversation_id, user_id, role, content, input_tokens, output_tokens, created_at FROM __temp__chat_message",
        "DROP TABLE __temp__chat_message",
        "CREATE INDEX IDX_CHAT_MSG_ROLE_CREATED ON chat_message (role, created_at)",
        "CREATE INDEX IDX_CHAT_MSG_USER_ROLE_CREATED ON chat_message (user_id, role, created_at)",
        "CREATE INDEX IDX_FAB3FC16A76ED395 ON chat_message (user_id)",
        "CREATE INDEX IDX_FAB3FC169AC0396 ON chat_message (conversation_id)",
    ];
}
