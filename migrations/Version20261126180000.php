<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** The ZENOO app subscription on the account (a month's free trial, then monthly, through Stripe). */
final class Version20261126180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'user subscription columns';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE `user` ADD subscription_status VARCHAR(20) DEFAULT NULL, ADD subscription_id VARCHAR(80) DEFAULT NULL, ADD stripe_customer_id VARCHAR(80) DEFAULT NULL, ADD subscription_period_end DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
            $this->addSql('CREATE INDEX IDX_USER_SUBSCRIPTION ON `user` (subscription_id)');
            $this->addSql('CREATE INDEX IDX_USER_STRIPE_CUSTOMER ON `user` (stripe_customer_id)');
        } elseif ($platform instanceof SqlitePlatform) {
            $this->addSql('ALTER TABLE user ADD COLUMN subscription_status VARCHAR(20) DEFAULT NULL');
            $this->addSql('ALTER TABLE user ADD COLUMN subscription_id VARCHAR(80) DEFAULT NULL');
            $this->addSql('ALTER TABLE user ADD COLUMN stripe_customer_id VARCHAR(80) DEFAULT NULL');
            $this->addSql('ALTER TABLE user ADD COLUMN subscription_period_end DATETIME DEFAULT NULL');
            $this->addSql('CREATE INDEX IDX_USER_SUBSCRIPTION ON user (subscription_id)');
            $this->addSql('CREATE INDEX IDX_USER_STRIPE_CUSTOMER ON user (stripe_customer_id)');
        } else {
            $this->abortIf(true, 'This migration supports MySQL (production) and SQLite (development) only.');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP subscription_status, DROP subscription_id, DROP stripe_customer_id, DROP subscription_period_end');
    }
}
