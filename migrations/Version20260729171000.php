<?php

declare(strict_types=1);

namespace DoctrineMigrations\MultiTenancy;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260729171000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Normalize tenant database connection data into database_connections.';
    }

    public function up(Schema $schema): void
    {
        if (!$this->tableExists('databases')) {
            return;
        }

        $this->addSql('CREATE TABLE IF NOT EXISTS `database_connections` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `db_driver` varchar(80) NOT NULL DEFAULT "pdo_mysql",
  `db_instance` varchar(255) NOT NULL DEFAULT "",
  `db_port` int(11) NOT NULL DEFAULT 3306,
  `db_host` varchar(255) NOT NULL,
  `db_name` varchar(255) NOT NULL,
  `db_user` varchar(255) NOT NULL,
  `db_password` varbinary(255) NOT NULL,
  `connection_hash` char(64) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `database_connections_hash_unique` (`connection_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        if (!$this->columnExists('databases', 'database_connection_id')) {
            $this->addSql('ALTER TABLE `databases` ADD `database_connection_id` int(11) DEFAULT NULL AFTER `id`');
            $this->addSql('CREATE INDEX `databases_database_connection_id_idx` ON `databases` (`database_connection_id`)');
        }

        if ($this->columnExists('databases', 'db_host')) {
            $this->addSql('INSERT INTO `database_connections` (
                    `db_driver`,
                    `db_instance`,
                    `db_port`,
                    `db_host`,
                    `db_name`,
                    `db_user`,
                    `db_password`,
                    `connection_hash`
                )
                SELECT
                    source.`db_driver`,
                    COALESCE(source.`db_instance`, ""),
                    source.`db_port`,
                    source.`db_host`,
                    source.`db_name`,
                    source.`db_user`,
                    source.`db_password`,
                    SHA2(CONCAT_WS("|",
                        source.`db_driver`,
                        source.`db_host`,
                        source.`db_name`,
                        source.`db_user`,
                        source.`db_port`,
                        COALESCE(source.`db_instance`, "")
                    ), 256)
                FROM `databases` source
                INNER JOIN (
                    SELECT MIN(`id`) AS `id`
                    FROM `databases`
                    GROUP BY
                        `db_driver`,
                        `db_host`,
                        `db_name`,
                        `db_user`,
                        `db_port`,
                        COALESCE(`db_instance`, "")
                ) grouped_source ON grouped_source.`id` = source.`id`
                WHERE NOT EXISTS (
                    SELECT 1
                    FROM `database_connections` existing
                    WHERE existing.`connection_hash` = SHA2(CONCAT_WS("|",
                        source.`db_driver`,
                        source.`db_host`,
                        source.`db_name`,
                        source.`db_user`,
                        source.`db_port`,
                        COALESCE(source.`db_instance`, "")
                    ), 256)
                )');

            $this->addSql('UPDATE `databases` target
                INNER JOIN `database_connections` connection
                    ON connection.`connection_hash` = SHA2(CONCAT_WS("|",
                        target.`db_driver`,
                        target.`db_host`,
                        target.`db_name`,
                        target.`db_user`,
                        target.`db_port`,
                        COALESCE(target.`db_instance`, "")
                    ), 256)
                SET target.`database_connection_id` = connection.`id`
                WHERE target.`database_connection_id` IS NULL');
        }

        if (!$this->foreignKeyExists('databases', 'databases_database_connection_id_fk')) {
            $this->addSql('ALTER TABLE `databases`
                ADD CONSTRAINT `databases_database_connection_id_fk`
                FOREIGN KEY (`database_connection_id`) REFERENCES `database_connections` (`id`)
                ON DELETE RESTRICT ON UPDATE CASCADE');
        }

        $this->dropLegacyDatabaseColumns();
    }

    public function down(Schema $schema): void
    {
        return;
    }

    private function dropLegacyDatabaseColumns(): void
    {
        $legacyColumns = [
            'db_driver',
            'db_instance',
            'db_port',
            'db_host',
            'db_name',
            'db_user',
            'db_password',
        ];

        foreach ($legacyColumns as $columnName) {
            if (!$this->columnExists('databases', $columnName)) {
                continue;
            }

            $this->addSql(sprintf('ALTER TABLE `databases` DROP COLUMN `%s`', $columnName));
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
}
