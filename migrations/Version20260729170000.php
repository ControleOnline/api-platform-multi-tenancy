<?php

declare(strict_types=1);

namespace DoctrineMigrations\MultiTenancy;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260729170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Move cron scheduling to the master database and use centralized logs.';
    }

    public function up(Schema $schema): void
    {
        if (!$this->tableExists('databases')) {
            return;
        }

        $this->addSql('CREATE TABLE IF NOT EXISTS `cron_jobs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `scope` enum("master", "tenant") NOT NULL DEFAULT "tenant",
  `title` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `cron_expression` varchar(120) NOT NULL,
  `command` varchar(255) NOT NULL,
  `arguments` json NOT NULL,
  `last_execution_at` datetime DEFAULT NULL,
  `last_status` varchar(20) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `cron_jobs_scope_enabled_idx` (`scope`, `enabled`),
  KEY `cron_jobs_command_idx` (`command`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $this->dropCronJobExecutionContextColumns();

        $this->addSql('CREATE TABLE IF NOT EXISTS `log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `user_id` int(11) DEFAULT NULL,
  `type` varchar(64) CHARACTER SET utf8 NOT NULL,
  `row` int(11) DEFAULT NULL,
  `action` varchar(255) CHARACTER SET utf8 NOT NULL,
  `class` varchar(255) CHARACTER SET utf8 NOT NULL,
  `object` longtext CHARACTER SET utf8 NOT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `log_type_created_at_idx` (`type`, `created_at`),
  KEY `log_class_row_idx` (`class`, `row`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $this->moveCronJobLogsToGenericLog();

        foreach ($this->getDefaultCronJobs() as $job) {
            $this->addSql(
                'INSERT INTO `cron_jobs` (`scope`, `title`, `description`, `enabled`, `cron_expression`, `command`, `arguments`)
                 SELECT :scope, :title, :description, :enabled, :cron_expression, :command, :arguments
                 FROM DUAL
                 WHERE NOT EXISTS (
                     SELECT 1
                     FROM `cron_jobs`
                     WHERE `scope` = :scope
                       AND `command` = :command
                 )',
                [
                    'scope' => $job['scope'],
                    'title' => $job['title'],
                    'description' => $job['description'],
                    'enabled' => $job['enabled'] ? 1 : 0,
                    'cron_expression' => $job['cronExpression'],
                    'command' => $job['command'],
                    'arguments' => json_encode($job['arguments'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]
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

    private function dropCronJobExecutionContextColumns(): void
    {
        foreach (['cron_jobs_database_id_fk', 'cron_jobs_server_id_fk'] as $constraintName) {
            if ($this->foreignKeyExists('cron_jobs', $constraintName)) {
                $this->addSql(sprintf('ALTER TABLE `cron_jobs` DROP FOREIGN KEY `%s`', $constraintName));
            }
        }

        foreach (['cron_jobs_database_id_idx', 'cron_jobs_server_id_idx'] as $indexName) {
            if ($this->indexExists('cron_jobs', $indexName)) {
                $this->addSql(sprintf('DROP INDEX `%s` ON `cron_jobs`', $indexName));
            }
        }

        foreach (['database_id', 'server_id'] as $columnName) {
            if ($this->columnExists('cron_jobs', $columnName)) {
                $this->addSql(sprintf('ALTER TABLE `cron_jobs` DROP COLUMN `%s`', $columnName));
            }
        }
    }

    private function moveCronJobLogsToGenericLog(): void
    {
        if (!$this->tableExists('cron_job_logs')) {
            return;
        }

        $this->addSql('INSERT INTO `log` (`created_at`, `user_id`, `type`, `row`, `action`, `class`, `object`)
            SELECT
                cron_job_logs.`created_at`,
                NULL,
                "cron",
                cron_job_logs.`cron_job_id`,
                cron_job_logs.`status`,
                "ControleOnline\\\\Entity\\\\CronJob",
                JSON_OBJECT(
                    "cronJobId", cron_job_logs.`cron_job_id`,
                    "databaseId", cron_job_logs.`database_id`,
                    "serverId", cron_job_logs.`server_id`,
                    "status", cron_job_logs.`status`,
                    "exitCode", cron_job_logs.`exit_code`,
                    "message", cron_job_logs.`message`,
                    "output", cron_job_logs.`output`,
                    "startedAt", DATE_FORMAT(cron_job_logs.`started_at`, "%Y-%m-%dT%H:%i:%s"),
                    "finishedAt", DATE_FORMAT(cron_job_logs.`finished_at`, "%Y-%m-%dT%H:%i:%s"),
                    "durationMs", cron_job_logs.`duration_ms`
                )
            FROM `cron_job_logs`
            WHERE NOT EXISTS (
                SELECT 1
                FROM `log` existing_log
                WHERE existing_log.`type` = "cron"
                  AND existing_log.`row` <=> cron_job_logs.`cron_job_id`
                  AND existing_log.`action` = cron_job_logs.`status`
                  AND existing_log.`created_at` = cron_job_logs.`created_at`
            )');

        $this->addSql('DROP TABLE `cron_job_logs`');
    }

    /**
     * @return array<int, array{
     *     scope: string,
     *     title: string,
     *     description: string,
     *     enabled: bool,
     *     cronExpression: string,
     *     command: string,
     *     arguments: array<int, string>
     * }>
     */
    private function getDefaultCronJobs(): array
    {
        return [
            [
                'scope' => 'master',
                'title' => 'Instalacao de tenants pendentes',
                'description' => 'Instala tenants registrados no master com status pending.',
                'enabled' => true,
                'cronExpression' => '* * * * *',
                'command' => 'tenant:install:pending',
                'arguments' => ['--limit=3'],
            ],
            [
                'scope' => 'tenant',
                'title' => 'Consumer async por tenant',
                'description' => 'Consome mensagens async dentro de cada tenant instalado.',
                'enabled' => true,
                'cronExpression' => '* * * * *',
                'command' => 'tenant:messenger:consume',
                'arguments' => ['async', '--time-limit=50'],
            ],
            [
                'scope' => 'tenant',
                'title' => 'Integracoes por tenant',
                'description' => 'Processa a fila de integracoes dentro de cada tenant instalado.',
                'enabled' => true,
                'cronExpression' => '* * * * *',
                'command' => 'tenant:integration:start',
                'arguments' => [],
            ],
            [
                'scope' => 'tenant',
                'title' => 'Importacoes por tenant',
                'description' => 'Processa a fila de importacoes dentro de cada tenant instalado.',
                'enabled' => true,
                'cronExpression' => '* * * * *',
                'command' => 'import:start',
                'arguments' => [],
            ],
            [
                'scope' => 'tenant',
                'title' => 'Manutencao por tenant',
                'description' => 'Executa as rotinas de manutencao dentro de cada tenant instalado.',
                'enabled' => true,
                'cronExpression' => '* * * * *',
                'command' => 'app:maintenance:run',
                'arguments' => [],
            ],
            [
                'scope' => 'master',
                'title' => 'Limpeza dos logs de crons',
                'description' => 'Remove registros antigos de cron da tabela central de logs.',
                'enabled' => true,
                'cronExpression' => '17 3 * * *',
                'command' => 'app:cron:logs:cleanup',
                'arguments' => ['--retention-days=30'],
            ],
        ];
    }
}
