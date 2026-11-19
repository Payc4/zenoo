<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Roadmap 07: more than one dog per account; the account remembers which one is selected. */
final class Version20261119120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'dog_profile.user_id no longer unique; user.active_dog_id';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            // The foreign key needs an index of its own before the unique one goes.
            $this->addSql('CREATE INDEX IDX_DOG_PROFILE_USER ON dog_profile (user_id)');
            $this->addSql('DROP INDEX UNIQ_DOG_PROFILE_USER ON dog_profile');
            $this->addSql('ALTER TABLE `user` ADD active_dog_id INT DEFAULT NULL');
        } elseif ($platform instanceof SqlitePlatform) {
            $this->addSql('DROP INDEX UNIQ_DOG_PROFILE_USER');
            $this->addSql('CREATE INDEX IDX_DOG_PROFILE_USER ON dog_profile (user_id)');
            $this->addSql('ALTER TABLE "user" ADD COLUMN active_dog_id INTEGER DEFAULT NULL');
        } else {
            $this->abortIf(true, 'This migration supports MySQL (production) and SQLite (development) only.');
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Accounts may now have several dogs.');
    }
}
