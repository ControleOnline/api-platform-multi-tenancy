<?php

declare(strict_types=1);

namespace DoctrineMigrations\MultiTenancy;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260728110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add tenant installation status to the master tenancy table.';
    }

    public function up(Schema $schema): void
    {
        if (!$this->tableExists('databases')) {
            return;
        }

        if (!$this->columnExists('databases', 'installation_status')) {
            $this->addSql('ALTER TABLE `databases` ADD `installation_status` ENUM("pending", "installing", "installed", "failed") NOT NULL DEFAULT "pending" AFTER `db_password`');
            $this->addSql(
                'UPDATE `databases`
                 SET `installation_status` = "installed"
                 WHERE `installation_status` = "pending"'
            );
        }
    }

    public function down(Schema $schema): void
    {
        return;
    }

    private function tableExists(string $tableName): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*)
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?',
            [$tableName]
        ) > 0;
    }

    private function columnExists(string $tableName, string $columnName): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*)
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?',
            [$tableName, $columnName]
        ) > 0;
    }
}
