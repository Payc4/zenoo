<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Quiz → account: quiz submissions waiting for email confirmation, one dog profile per
 * account, and each user's site language plus whether they have chosen a password.
 * Statements generated from the entity mapping for MySQL (production) and SQLite (development).
 */
final class Version20261107120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Dog profiles, quiz submissions, user language';
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
        $this->addSql('DROP TABLE dog_profile');
        $this->addSql('DROP TABLE quiz_submission');
    }

    private const MYSQL = [
            "CREATE TABLE quiz_submission (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, first_name VARCHAR(100) DEFAULT NULL, locale VARCHAR(5) NOT NULL, data JSON NOT NULL, token_hash VARCHAR(64) NOT NULL, ip_hash VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', confirmed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', UNIQUE INDEX UNIQ_926A7DCFB3BC57DA (token_hash), INDEX IDX_QUIZ_SUBMISSION_EMAIL (email), INDEX IDX_QUIZ_SUBMISSION_IP (ip_hash), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB",
            "CREATE TABLE dog_profile (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, name VARCHAR(60) NOT NULL, bowl_score SMALLINT NOT NULL, locale VARCHAR(5) NOT NULL, data JSON NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', UNIQUE INDEX UNIQ_DOG_PROFILE_USER (user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB",
            "ALTER TABLE dog_profile ADD CONSTRAINT FK_35973DDA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE",
            "ALTER TABLE `user` ADD locale VARCHAR(5) DEFAULT 'en' NOT NULL, ADD has_password TINYINT(1) DEFAULT 1 NOT NULL",
    ];

    private const SQLITE = [
            "CREATE TABLE quiz_submission (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, email VARCHAR(180) NOT NULL, first_name VARCHAR(100) DEFAULT NULL, locale VARCHAR(5) NOT NULL, data CLOB NOT NULL --(DC2Type:json)\n, token_hash VARCHAR(64) NOT NULL, ip_hash VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)\n, confirmed_at DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)\n)",
            "CREATE UNIQUE INDEX UNIQ_926A7DCFB3BC57DA ON quiz_submission (token_hash)",
            "CREATE INDEX IDX_QUIZ_SUBMISSION_EMAIL ON quiz_submission (email)",
            "CREATE INDEX IDX_QUIZ_SUBMISSION_IP ON quiz_submission (ip_hash)",
            "CREATE TABLE dog_profile (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, user_id INTEGER NOT NULL, name VARCHAR(60) NOT NULL, bowl_score SMALLINT NOT NULL, locale VARCHAR(5) NOT NULL, data CLOB NOT NULL --(DC2Type:json)\n, created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)\n, updated_at DATETIME NOT NULL --(DC2Type:datetime_immutable)\n, CONSTRAINT FK_35973DDA76ED395 FOREIGN KEY (user_id) REFERENCES \"user\" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)",
            "CREATE UNIQUE INDEX UNIQ_DOG_PROFILE_USER ON dog_profile (user_id)",
            "ALTER TABLE \"user\" ADD COLUMN locale VARCHAR(5) DEFAULT 'en' NOT NULL",
            "ALTER TABLE \"user\" ADD COLUMN has_password BOOLEAN DEFAULT 1 NOT NULL",
    ];
}
