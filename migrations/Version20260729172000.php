<?php

declare(strict_types=1);

namespace DoctrineMigrations\MultiTenancy;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260729172000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Move tenant database connection data into servers and keep databases linked by server_id.';
    }

    public function up(Schema $schema): void
    {
        if (!$this->tableExists('databases') || !$this->tableExists('servers')) {
            return;
        }

        $this->prepareServersTable();
        $this->prepareDatabasesTable();

        if ($this->tableExists('database_connections') && $this->columnExists('databases', 'database_connection_id')) {
            $this->copyDatabaseConnectionsToServers();
            $this->linkDatabasesFromDatabaseConnections();
            $this->dropDatabaseConnectionsSchema();
        } elseif ($this->columnExists('databases', 'db_host')) {
            $this->copyLegacyDatabaseRowsToServers();
            $this->linkDatabasesFromLegacyColumns();
        }

        $this->addDatabaseServerForeignKey();
        $this->dropLegacyDatabaseColumns();
    }

    public function down(Schema $schema): void
    {
        return;
    }

    private function prepareServersTable(): void
    {
        $this->addSql('ALTER TABLE `servers` MODIFY `driver` ENUM("ssh", "ftp", "pdo_mysql", "pdo_sqlsrv") NOT NULL DEFAULT "ssh"');

        if (!$this->columnExists('servers', 'db_name')) {
            $this->addSql('ALTER TABLE `servers` ADD `db_name` varchar(255) DEFAULT NULL AFTER `host`');
        }

        if (!$this->columnExists('servers', 'db_instance')) {
            $this->addSql('ALTER TABLE `servers` ADD `db_instance` varchar(255) DEFAULT NULL AFTER `db_name`');
        }

        if (!$this->columnExists('servers', 'connection_hash')) {
            $this->addSql('ALTER TABLE `servers` ADD `connection_hash` char(64) DEFAULT NULL AFTER `password`');
        }

        if (!$this->indexExists('servers', 'servers_connection_hash_unique')) {
            $this->addSql('CREATE UNIQUE INDEX `servers_connection_hash_unique` ON `servers` (`connection_hash`)');
        }

        if ($this->columnExists('servers', 'app_host')) {
            $this->addSql('ALTER TABLE `servers` DROP COLUMN `app_host`');
        }
    }

    private function prepareDatabasesTable(): void
    {
        if (!$this->columnExists('databases', 'server_id')) {
            $this->addSql('ALTER TABLE `databases` ADD `server_id` int(11) DEFAULT NULL AFTER `id`');
        }

        if (!$this->indexExists('databases', 'databases_server_id_idx')) {
            $this->addSql('CREATE INDEX `databases_server_id_idx` ON `databases` (`server_id`)');
        }
    }

    private function copyDatabaseConnectionsToServers(): void
    {
        $this->addSql('INSERT INTO `servers` (
                `driver`,
                `port`,
                `host`,
                `db_name`,
                `db_instance`,
                `user`,
                `password`,
                `connection_hash`
            )
            SELECT
                database_connections.`db_driver`,
                database_connections.`db_port`,
                database_connections.`db_host`,
                database_connections.`db_name`,
                NULLIF(database_connections.`db_instance`, ""),
                database_connections.`db_user`,
                database_connections.`db_password`,
                database_connections.`connection_hash`
            FROM `database_connections`
            INNER JOIN `databases`
                ON databases.`database_connection_id` = database_connections.`id`
            WHERE NOT EXISTS (
                SELECT 1
                FROM `servers` existing
                WHERE existing.`connection_hash` = database_connections.`connection_hash`
            )
            GROUP BY
                database_connections.`id`,
                database_connections.`db_driver`,
                database_connections.`db_port`,
                database_connections.`db_host`,
                database_connections.`db_name`,
                database_connections.`db_instance`,
                database_connections.`db_user`,
                database_connections.`db_password`,
                database_connections.`connection_hash`');
    }

    private function linkDatabasesFromDatabaseConnections(): void
    {
        $this->addSql('UPDATE `databases`
            INNER JOIN `database_connections`
                ON database_connections.`id` = databases.`database_connection_id`
            INNER JOIN `servers`
                ON servers.`connection_hash` = database_connections.`connection_hash`
            SET databases.`server_id` = servers.`id`
            WHERE databases.`server_id` IS NULL');
    }

    private function copyLegacyDatabaseRowsToServers(): void
    {
        $this->addSql('INSERT INTO `servers` (
                `driver`,
                `port`,
                `host`,
                `db_name`,
                `db_instance`,
                `user`,
                `password`,
                `connection_hash`
            )
            SELECT
                source.`db_driver`,
                source.`db_port`,
                source.`db_host`,
                source.`db_name`,
                NULLIF(COALESCE(source.`db_instance`, ""), ""),
                source.`db_user`,
                source.`db_password`,
                source.`connection_hash`
            FROM (
                SELECT
                    `db_driver`,
                    `db_host`,
                    `db_name`,
                    `db_user`,
                    `db_port`,
                    COALESCE(`db_instance`, "") AS `db_instance`,
                    `db_password`,
                    SHA2(CONCAT_WS("|",
                        `db_driver`,
                        `db_host`,
                        `db_name`,
                        `db_user`,
                        `db_port`,
                        COALESCE(`db_instance`, "")
                    ), 256) AS `connection_hash`
                FROM `databases`
            ) source
            WHERE NOT EXISTS (
                SELECT 1
                FROM `servers` existing
                WHERE existing.`connection_hash` = source.`connection_hash`
            )
            GROUP BY
                source.`db_driver`,
                source.`db_host`,
                source.`db_name`,
                source.`db_user`,
                source.`db_port`,
                COALESCE(source.`db_instance`, ""),
                source.`db_password`,
                source.`connection_hash`');
    }

    private function linkDatabasesFromLegacyColumns(): void
    {
        $this->addSql('UPDATE `databases` d
            INNER JOIN `servers` s
                ON s.`connection_hash` = SHA2(CONCAT_WS("|",
                    d.`db_driver`,
                    d.`db_host`,
                    d.`db_name`,
                    d.`db_user`,
                    d.`db_port`,
                    COALESCE(d.`db_instance`, "")
                ), 256)
            SET d.`server_id` = s.`id`
            WHERE d.`server_id` IS NULL');
    }

    private function addDatabaseServerForeignKey(): void
    {
        if ($this->foreignKeyExists('databases', 'databases_server_id_fk')) {
            return;
        }

        $this->addSql('ALTER TABLE `databases`
            ADD CONSTRAINT `databases_server_id_fk`
            FOREIGN KEY (`server_id`) REFERENCES `servers` (`id`)
            ON DELETE RESTRICT ON UPDATE CASCADE');
    }

    private function dropDatabaseConnectionsSchema(): void
    {
        if ($this->foreignKeyExists('databases', 'databases_database_connection_id_fk')) {
            $this->addSql('ALTER TABLE `databases` DROP FOREIGN KEY `databases_database_connection_id_fk`');
        }

        if ($this->columnExists('databases', 'database_connection_id')) {
            if ($this->indexExists('databases', 'databases_database_connection_id_idx')) {
                $this->addSql('DROP INDEX `databases_database_connection_id_idx` ON `databases`');
            }
            $this->addSql('ALTER TABLE `databases` DROP COLUMN `database_connection_id`');
        }

        $this->addSql('DROP TABLE IF EXISTS `database_connections`');
    }

    private function dropLegacyDatabaseColumns(): void
    {
        foreach (['db_driver', 'db_instance', 'db_port', 'db_host', 'db_name', 'db_user', 'db_password'] as $columnName) {
            if ($this->columnExists('databases', $columnName)) {
                $this->addSql(sprintf('ALTER TABLE `databases` DROP COLUMN `%s`', $columnName));
            }
        }
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

    private function foreignKeyExists(string $tableName, string $constraintName): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*)
             FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND CONSTRAINT_NAME = ?
               AND CONSTRAINT_TYPE = "FOREIGN KEY"',
            [$tableName, $constraintName]
        ) > 0;
    }

    private function indexExists(string $tableName, string $indexName): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*)
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND INDEX_NAME = ?',
            [$tableName, $indexName]
        ) > 0;
    }
}
