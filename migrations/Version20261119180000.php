<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Locked lessons with a one-off Stripe unlock, and one chat per pet.
 *
 * - lesson.is_locked: the last lesson of each course starts locked (change it in the Course studio).
 * - product.unlocks_lessons: buying such a product opens every locked lesson. An "All lessons"
 *   product (€9.99, change it in admin → Products) is created when none exists.
 * - chat_conversation.dog_id (unique): each pet has its own chat. The old chats, which weren't
 *   tied to a pet, are deleted (still in testing).
 */
final class Version20261119180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'lesson.is_locked, product.unlocks_lessons, chat_conversation.dog_id (one chat per pet)';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->addSql('DELETE FROM chat_message');
        $this->addSql('DELETE FROM chat_conversation');
        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE lesson ADD is_locked TINYINT(1) DEFAULT 0 NOT NULL');
            $this->addSql('ALTER TABLE product ADD unlocks_lessons TINYINT(1) DEFAULT 0 NOT NULL');
            $this->addSql('ALTER TABLE chat_conversation ADD dog_id INT DEFAULT NULL');
            $this->addSql('ALTER TABLE chat_conversation ADD CONSTRAINT FK_74654F68634DFEB FOREIGN KEY (dog_id) REFERENCES dog_profile (id) ON DELETE CASCADE');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_74654F68634DFEB ON chat_conversation (dog_id)');
        } elseif ($platform instanceof SqlitePlatform) {
            $this->addSql('ALTER TABLE lesson ADD COLUMN is_locked BOOLEAN DEFAULT 0 NOT NULL');
            $this->addSql('ALTER TABLE product ADD COLUMN unlocks_lessons BOOLEAN DEFAULT 0 NOT NULL');
            $this->addSql('ALTER TABLE chat_conversation ADD COLUMN dog_id INTEGER DEFAULT NULL REFERENCES dog_profile (id) ON DELETE CASCADE');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_74654F68634DFEB ON chat_conversation (dog_id)');
        } else {
            $this->abortIf(true, 'This migration supports MySQL (production) and SQLite (development) only.');
        }
    }

    public function postUp(Schema $schema): void
    {
        // The last lesson of every course starts locked.
        $last = [];
        foreach ($this->connection->fetchAllAssociative('SELECT id, course_id FROM lesson ORDER BY course_id, position, id') as $row) {
            $last[$row['course_id']] = $row['id'];
        }
        foreach ($last as $lessonId) {
            $this->connection->executeStatement('UPDATE lesson SET is_locked = 1 WHERE id = ?', [$lessonId]);
        }

        // The product that unlocks them.
        if ((int) $this->connection->fetchOne('SELECT COUNT(*) FROM product WHERE unlocks_lessons = 1') === 0) {
            $slug = 'all-lessons';
            if ((int) $this->connection->fetchOne('SELECT COUNT(*) FROM product WHERE slug = ?', [$slug]) > 0) {
                $slug = 'all-lessons-unlock';
            }
            $this->connection->insert('product', [
                'name' => 'All ZENOO lessons',
                'slug' => $slug,
                'tagline' => 'Unlocks every lesson in the ZENOO course, for good.',
                'description' => null,
                'price' => '9.99',
                'image' => null,
                'active' => 1,
                'unlocks_lessons' => 1,
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_74654F68634DFEB ON chat_conversation');
        $this->addSql('ALTER TABLE chat_conversation DROP FOREIGN KEY FK_74654F68634DFEB');
        $this->addSql('ALTER TABLE chat_conversation DROP dog_id');
        $this->addSql('ALTER TABLE product DROP unlocks_lessons');
        $this->addSql('ALTER TABLE lesson DROP is_locked');
    }
}
