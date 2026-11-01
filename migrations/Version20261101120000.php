<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Initial schema: accounts, products, purchases and the Messenger email queue,
 * plus the €1 test product.
 *
 * MySQL 8 is production. SQLite is only for local development and tests.
 * Both statement lists were generated from the entity mapping, so they match it exactly.
 */
final class Version20261101120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Accounts, products, purchases, email queue, and the test product';
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

        $this->addSql(
            'INSERT INTO product (name, slug, tagline, description, price, image, active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                'ZENOO Test Product',
                'test-product',
                'A €1 order to try the account and checkout. Nothing is shipped.',
                "This is a test product for checking the ZENOO account and checkout from start to finish.\n\nBuying it creates a real order in your account and sends a receipt by email, but nothing is shipped.",
                '1.00',
                'stage-adult.jpg',
                1,
                '2026-11-01 12:00:00',
            ],
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE purchase');
        $this->addSql('DROP TABLE product');
        $this->addSql($this->connection->getDatabasePlatform() instanceof SqlitePlatform ? 'DROP TABLE "user"' : 'DROP TABLE `user`');
        $this->addSql('DROP TABLE messenger_messages');
    }

    private const MYSQL = [
            "CREATE TABLE product (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(150) NOT NULL, slug VARCHAR(150) NOT NULL, tagline VARCHAR(255) DEFAULT NULL, description LONGTEXT DEFAULT NULL, price NUMERIC(10, 2) NOT NULL, image VARCHAR(255) DEFAULT NULL, active TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', UNIQUE INDEX UNIQ_PRODUCT_SLUG (slug), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB",
            "CREATE TABLE purchase (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, product_id INT NOT NULL, amount NUMERIC(10, 2) NOT NULL, currency VARCHAR(3) NOT NULL, discount_amount NUMERIC(10, 2) DEFAULT NULL, status VARCHAR(20) NOT NULL, stripe_session_id VARCHAR(255) NOT NULL, stripe_payment_intent_id VARCHAR(255) DEFAULT NULL, test_mode TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_6117D13BA76ED395 (user_id), INDEX IDX_6117D13B4584665A (product_id), INDEX IDX_PURCHASE_PAYMENT_INTENT (stripe_payment_intent_id), UNIQUE INDEX UNIQ_PURCHASE_STRIPE_SESSION (stripe_session_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB",
            "CREATE TABLE `user` (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, first_name VARCHAR(100) DEFAULT NULL, last_name VARCHAR(100) DEFAULT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', last_login_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', profile_image VARCHAR(255) DEFAULT NULL, reset_token_hash VARCHAR(64) DEFAULT NULL, reset_token_expires_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', last_magic_link_requested_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', UNIQUE INDEX UNIQ_USER_EMAIL (email), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB",
            "CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', available_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', delivered_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_75EA56E0FB7336F0 (queue_name), INDEX IDX_75EA56E0E3BD61CE (available_at), INDEX IDX_75EA56E016BA31DB (delivered_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB",
            "ALTER TABLE purchase ADD CONSTRAINT FK_6117D13BA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE",
            "ALTER TABLE purchase ADD CONSTRAINT FK_6117D13B4584665A FOREIGN KEY (product_id) REFERENCES product (id)",
    ];

    private const SQLITE = [
            "CREATE TABLE product (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(150) NOT NULL, slug VARCHAR(150) NOT NULL, tagline VARCHAR(255) DEFAULT NULL, description CLOB DEFAULT NULL, price NUMERIC(10, 2) NOT NULL, image VARCHAR(255) DEFAULT NULL, active BOOLEAN NOT NULL, created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)\n)",
            "CREATE UNIQUE INDEX UNIQ_PRODUCT_SLUG ON product (slug)",
            "CREATE TABLE purchase (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, user_id INTEGER NOT NULL, product_id INTEGER NOT NULL, amount NUMERIC(10, 2) NOT NULL, currency VARCHAR(3) NOT NULL, discount_amount NUMERIC(10, 2) DEFAULT NULL, status VARCHAR(20) NOT NULL, stripe_session_id VARCHAR(255) NOT NULL, stripe_payment_intent_id VARCHAR(255) DEFAULT NULL, test_mode BOOLEAN NOT NULL, created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)\n, CONSTRAINT FK_6117D13BA76ED395 FOREIGN KEY (user_id) REFERENCES \"user\" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_6117D13B4584665A FOREIGN KEY (product_id) REFERENCES product (id) NOT DEFERRABLE INITIALLY IMMEDIATE)",
            "CREATE INDEX IDX_6117D13BA76ED395 ON purchase (user_id)",
            "CREATE INDEX IDX_6117D13B4584665A ON purchase (product_id)",
            "CREATE INDEX IDX_PURCHASE_PAYMENT_INTENT ON purchase (stripe_payment_intent_id)",
            "CREATE UNIQUE INDEX UNIQ_PURCHASE_STRIPE_SESSION ON purchase (stripe_session_id)",
            "CREATE TABLE \"user\" (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, email VARCHAR(180) NOT NULL, first_name VARCHAR(100) DEFAULT NULL, last_name VARCHAR(100) DEFAULT NULL, roles CLOB NOT NULL --(DC2Type:json)\n, password VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)\n, last_login_at DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)\n, profile_image VARCHAR(255) DEFAULT NULL, reset_token_hash VARCHAR(64) DEFAULT NULL, reset_token_expires_at DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)\n, last_magic_link_requested_at DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)\n)",
            "CREATE UNIQUE INDEX UNIQ_USER_EMAIL ON \"user\" (email)",
            "CREATE TABLE messenger_messages (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, body CLOB NOT NULL, headers CLOB NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)\n, available_at DATETIME NOT NULL --(DC2Type:datetime_immutable)\n, delivered_at DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)\n)",
            "CREATE INDEX IDX_75EA56E0FB7336F0 ON messenger_messages (queue_name)",
            "CREATE INDEX IDX_75EA56E0E3BD61CE ON messenger_messages (available_at)",
            "CREATE INDEX IDX_75EA56E016BA31DB ON messenger_messages (delivered_at)",
    ];
}
