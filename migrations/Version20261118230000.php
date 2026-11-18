<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** The main store's products (Shopify), copied by app:catalogue:sync. */
final class Version20261118230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'catalogue_product';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('CREATE TABLE catalogue_product (id INT AUTO_INCREMENT NOT NULL, shopify_id BIGINT NOT NULL, handle VARCHAR(255) NOT NULL, title VARCHAR(255) NOT NULL, product_type VARCHAR(120) DEFAULT NULL, tags JSON NOT NULL, image VARCHAR(500) DEFAULT NULL, variants JSON NOT NULL, description LONGTEXT DEFAULT NULL, kcal_parsed DOUBLE PRECISION DEFAULT NULL, analysis JSON DEFAULT NULL, kind_guess VARCHAR(20) NOT NULL, species_guess VARCHAR(10) NOT NULL, kcal_override DOUBLE PRECISION DEFAULT NULL, kind_override VARCHAR(20) DEFAULT NULL, species_override VARCHAR(10) DEFAULT NULL, hidden TINYINT(1) NOT NULL, notes VARCHAR(500) DEFAULT NULL, active TINYINT(1) NOT NULL, synced_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX UNIQ_CATALOGUE_SHOPIFY (shopify_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        } elseif ($platform instanceof SqlitePlatform) {
            $this->addSql('CREATE TABLE catalogue_product (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, shopify_id BIGINT NOT NULL, handle VARCHAR(255) NOT NULL, title VARCHAR(255) NOT NULL, product_type VARCHAR(120) DEFAULT NULL, tags CLOB NOT NULL, image VARCHAR(500) DEFAULT NULL, variants CLOB NOT NULL, description CLOB DEFAULT NULL, kcal_parsed DOUBLE PRECISION DEFAULT NULL, analysis CLOB DEFAULT NULL, kind_guess VARCHAR(20) NOT NULL, species_guess VARCHAR(10) NOT NULL, kcal_override DOUBLE PRECISION DEFAULT NULL, kind_override VARCHAR(20) DEFAULT NULL, species_override VARCHAR(10) DEFAULT NULL, hidden BOOLEAN NOT NULL, notes VARCHAR(500) DEFAULT NULL, active BOOLEAN NOT NULL, synced_at DATETIME NOT NULL)');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_CATALOGUE_SHOPIFY ON catalogue_product (shopify_id)');
        } else {
            $this->abortIf(true, 'This migration supports MySQL (production) and SQLite (development) only.');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE catalogue_product');
    }
}
