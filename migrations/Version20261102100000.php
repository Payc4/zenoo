<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Chatbot: conversations, messages (with token counts) and the per-day message counts
 * behind the daily limit.
 * Statements generated from the entity mapping for MySQL (production) and SQLite (development).
 */
final class Version20261102100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Chatbot conversations, messages and daily usage';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            $statements = self::MYSQL;
        } elseif ($platform instanceof SqlitePlatform) {
            $statements = self::SQLITE;
        } else {
            $this->abortIf(true, 'This migration supports MySQL (production) and SQLite (development) only.');
        }
        foreach ($statements as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE chat_usage');
        $this->addSql('DROP TABLE chat_message');
        $this->addSql('DROP TABLE chat_conversation');
    }

    private const MYSQL = [
            "CREATE TABLE chat_conversation (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, title VARCHAR(120) NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_74654F68A76ED395 (user_id), INDEX IDX_CHAT_CONV_USER_UPDATED (user_id, updated_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB",
            "CREATE TABLE chat_message (id INT AUTO_INCREMENT NOT NULL, conversation_id INT NOT NULL, user_id INT NOT NULL, role VARCHAR(16) NOT NULL, content LONGTEXT NOT NULL, input_tokens INT DEFAULT NULL, output_tokens INT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_FAB3FC169AC0396 (conversation_id), INDEX IDX_FAB3FC16A76ED395 (user_id), INDEX IDX_CHAT_MSG_USER_ROLE_CREATED (user_id, role, created_at), INDEX IDX_CHAT_MSG_ROLE_CREATED (role, created_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB",
            "CREATE TABLE chat_usage (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, day VARCHAR(10) NOT NULL, messages INT NOT NULL, INDEX IDX_D196B3EBA76ED395 (user_id), UNIQUE INDEX UNIQ_CHAT_USAGE_USER_DAY (user_id, day), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB",
            "ALTER TABLE chat_conversation ADD CONSTRAINT FK_74654F68A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE",
            "ALTER TABLE chat_message ADD CONSTRAINT FK_FAB3FC169AC0396 FOREIGN KEY (conversation_id) REFERENCES chat_conversation (id) ON DELETE CASCADE",
            "ALTER TABLE chat_message ADD CONSTRAINT FK_FAB3FC16A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE",
            "ALTER TABLE chat_usage ADD CONSTRAINT FK_D196B3EBA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE",
    ];

    private const SQLITE = [
            "CREATE TABLE chat_conversation (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, user_id INTEGER NOT NULL, title VARCHAR(120) NOT NULL, created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
, updated_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
, CONSTRAINT FK_74654F68A76ED395 FOREIGN KEY (user_id) REFERENCES \"user\" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)",
            "CREATE INDEX IDX_74654F68A76ED395 ON chat_conversation (user_id)",
            "CREATE INDEX IDX_CHAT_CONV_USER_UPDATED ON chat_conversation (user_id, updated_at)",
            "CREATE TABLE chat_message (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, conversation_id INTEGER NOT NULL, user_id INTEGER NOT NULL, role VARCHAR(16) NOT NULL, content CLOB NOT NULL, input_tokens INTEGER DEFAULT NULL, output_tokens INTEGER DEFAULT NULL, created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
, CONSTRAINT FK_FAB3FC169AC0396 FOREIGN KEY (conversation_id) REFERENCES chat_conversation (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_FAB3FC16A76ED395 FOREIGN KEY (user_id) REFERENCES \"user\" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)",
            "CREATE INDEX IDX_FAB3FC169AC0396 ON chat_message (conversation_id)",
            "CREATE INDEX IDX_FAB3FC16A76ED395 ON chat_message (user_id)",
            "CREATE INDEX IDX_CHAT_MSG_USER_ROLE_CREATED ON chat_message (user_id, role, created_at)",
            "CREATE INDEX IDX_CHAT_MSG_ROLE_CREATED ON chat_message (role, created_at)",
            "CREATE TABLE chat_usage (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, user_id INTEGER NOT NULL, day VARCHAR(10) NOT NULL, messages INTEGER NOT NULL, CONSTRAINT FK_D196B3EBA76ED395 FOREIGN KEY (user_id) REFERENCES \"user\" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)",
            "CREATE INDEX IDX_D196B3EBA76ED395 ON chat_usage (user_id)",
            "CREATE UNIQUE INDEX UNIQ_CHAT_USAGE_USER_DAY ON chat_usage (user_id, day)",
    ];
}
