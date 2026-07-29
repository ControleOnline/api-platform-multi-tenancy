<?php

declare(strict_types=1);

namespace DoctrineMigrations\MultiTenancy;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260729170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Move cron scheduling to the master database and add centralized cron execution logs.';
    }

    public function up(Schema $schema): void
    {
        if (!$this->tableExists('databases')) {
            return;
        }

        $this->addSql('CREATE TABLE IF NOT EXISTS `cron_jobs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `database_id` int(11) DEFAULT NULL,
  `server_id` int(11) DEFAULT NULL,
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
  KEY `cron_jobs_database_id_idx` (`database_id`),
  KEY `cron_jobs_server_id_idx` (`server_id`),
  KEY `cron_jobs_scope_enabled_idx` (`scope`, `enabled`),
  KEY `cron_jobs_command_idx` (`command`),
  CONSTRAINT `cron_jobs_database_id_fk` FOREIGN KEY (`database_id`) REFERENCES `databases` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        if ($this->tableExists('servers') && !$this->foreignKeyExists('cron_jobs', 'cron_jobs_server_id_fk')) {
            $this->addSql('ALTER TABLE `cron_jobs`
                ADD CONSTRAINT `cron_jobs_server_id_fk`
                FOREIGN KEY (`server_id`) REFERENCES `servers` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE');
        }

        $this->addSql('CREATE TABLE IF NOT EXISTS `cron_job_logs` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `cron_job_id` int(11) DEFAULT NULL,
  `database_id` int(11) DEFAULT NULL,
  `server_id` int(11) DEFAULT NULL,
  `status` varchar(20) NOT NULL,
  `exit_code` int(11) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `output` mediumtext DEFAULT NULL,
  `started_at` datetime NOT NULL,
  `finished_at` datetime NOT NULL,
  `duration_ms` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `cron_job_logs_cron_job_id_idx` (`cron_job_id`),
  KEY `cron_job_logs_database_id_idx` (`database_id`),
  KEY `cron_job_logs_server_id_idx` (`server_id`),
  KEY `cron_job_logs_created_at_idx` (`created_at`),
  CONSTRAINT `cron_job_logs_cron_job_id_fk` FOREIGN KEY (`cron_job_id`) REFERENCES `cron_jobs` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `cron_job_logs_database_id_fk` FOREIGN KEY (`database_id`) REFERENCES `databases` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        if ($this->tableExists('servers') && !$this->foreignKeyExists('cron_job_logs', 'cron_job_logs_server_id_fk')) {
            $this->addSql('ALTER TABLE `cron_job_logs`
                ADD CONSTRAINT `cron_job_logs_server_id_fk`
                FOREIGN KEY (`server_id`) REFERENCES `servers` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE');
        }

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
                       AND `database_id` IS NULL
                       AND `server_id` IS NULL
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
                'description' => 'Remove registros antigos da tabela central cron_job_logs.',
                'enabled' => true,
                'cronExpression' => '17 3 * * *',
                'command' => 'app:cron:logs:cleanup',
                'arguments' => ['--retention-days=30'],
            ],
        ];
    }
}
