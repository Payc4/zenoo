<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Courses: course, lesson (slides as JSON) and each learner's lesson progress.
 * The 30 ZENOO lessons are loaded with `php bin/console app:course:import` (see README).
 * Statements generated from the entity mapping for MySQL (production) and SQLite (development).
 */
final class Version20261104100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Courses, lessons and lesson progress';
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
        $this->addSql('DROP TABLE lesson_progress');
        $this->addSql('DROP TABLE lesson');
        $this->addSql('DROP TABLE course');
    }

    private const MYSQL = [
            "CREATE TABLE lesson (id INT AUTO_INCREMENT NOT NULL, course_id INT NOT NULL, title VARCHAR(255) NOT NULL, subtitle VARCHAR(255) DEFAULT NULL, position INT NOT NULL, duration_minutes INT DEFAULT NULL, is_published TINYINT(1) NOT NULL, slides JSON NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_F87474F3591CC992 (course_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB",
            "CREATE TABLE course (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, is_published TINYINT(1) NOT NULL, is_free TINYINT(1) NOT NULL, locale VARCHAR(5) DEFAULT 'en' NOT NULL, position INT NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', UNIQUE INDEX UNIQ_169E6FB9989D9B62 (slug), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB",
            "CREATE TABLE lesson_progress (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, lesson_id INT NOT NULL, current_slide INT NOT NULL, answers JSON NOT NULL, completed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', started_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_6A46B85FA76ED395 (user_id), INDEX IDX_6A46B85FCDF80196 (lesson_id), UNIQUE INDEX UNIQ_LESSON_PROGRESS_USER_LESSON (user_id, lesson_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB",
            "ALTER TABLE lesson ADD CONSTRAINT FK_F87474F3591CC992 FOREIGN KEY (course_id) REFERENCES course (id) ON DELETE CASCADE",
            "ALTER TABLE lesson_progress ADD CONSTRAINT FK_6A46B85FA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE",
            "ALTER TABLE lesson_progress ADD CONSTRAINT FK_6A46B85FCDF80196 FOREIGN KEY (lesson_id) REFERENCES lesson (id) ON DELETE CASCADE",
    ];

    private const SQLITE = [
            "CREATE TABLE lesson (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, course_id INTEGER NOT NULL, title VARCHAR(255) NOT NULL, subtitle VARCHAR(255) DEFAULT NULL, position INTEGER NOT NULL, duration_minutes INTEGER DEFAULT NULL, is_published BOOLEAN NOT NULL, slides CLOB NOT NULL --(DC2Type:json)\n, created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)\n, updated_at DATETIME NOT NULL --(DC2Type:datetime_immutable)\n, CONSTRAINT FK_F87474F3591CC992 FOREIGN KEY (course_id) REFERENCES course (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)",
            "CREATE INDEX IDX_F87474F3591CC992 ON lesson (course_id)",
            "CREATE TABLE course (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, title VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, description CLOB DEFAULT NULL, is_published BOOLEAN NOT NULL, is_free BOOLEAN NOT NULL, locale VARCHAR(5) DEFAULT 'en' NOT NULL, position INTEGER NOT NULL, created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)\n, updated_at DATETIME NOT NULL --(DC2Type:datetime_immutable)\n)",
            "CREATE UNIQUE INDEX UNIQ_169E6FB9989D9B62 ON course (slug)",
            "CREATE TABLE lesson_progress (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, user_id INTEGER NOT NULL, lesson_id INTEGER NOT NULL, current_slide INTEGER NOT NULL, answers CLOB NOT NULL --(DC2Type:json)\n, completed_at DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)\n, started_at DATETIME NOT NULL --(DC2Type:datetime_immutable)\n, updated_at DATETIME NOT NULL --(DC2Type:datetime_immutable)\n, CONSTRAINT FK_6A46B85FA76ED395 FOREIGN KEY (user_id) REFERENCES \"user\" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_6A46B85FCDF80196 FOREIGN KEY (lesson_id) REFERENCES lesson (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)",
            "CREATE INDEX IDX_6A46B85FA76ED395 ON lesson_progress (user_id)",
            "CREATE INDEX IDX_6A46B85FCDF80196 ON lesson_progress (lesson_id)",
            "CREATE UNIQUE INDEX UNIQ_LESSON_PROGRESS_USER_LESSON ON lesson_progress (user_id, lesson_id)",
    ];
}
