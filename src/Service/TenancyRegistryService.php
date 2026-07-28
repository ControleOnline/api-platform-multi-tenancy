<?php

namespace ControleOnline\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class TenancyRegistryService
{
    private const INSTALLATION_STATUSES = ['pending', 'installing', 'installed', 'failed'];

    public function __construct(
        private Connection $connection,
        private DatabaseSwitchService $databaseSwitchService,
    ) {}

    public function list(array $filters = []): array
    {
        $this->databaseSwitchService->switchBackToOriginalDatabase();
        $this->ensureInstallColumn();

        $where = [];
        $params = [];
        $types = [];

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $where[] = '(`app_host` LIKE :search OR `db_host` LIKE :search OR `db_name` LIKE :search OR `db_user` LIKE :search)';
            $params['search'] = '%' . $search . '%';
            $types['search'] = ParameterType::STRING;
        }

        $status = trim((string) ($filters['instalation_status'] ?? ''));
        if ($status !== '') {
            $where[] = '`instalation_status` = :status';
            $params['status'] = $status;
            $types['status'] = ParameterType::STRING;
        }

        $sql = 'SELECT `id`, `app_host`, `db_host`, `db_name`, `db_port`, `db_user`, `db_driver`, `db_instance`, `instalation_status`
                FROM `databases`';

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY `id` DESC LIMIT 500';

        return array_map(
            fn (array $row): array => $this->normalizeRow($row),
            $this->connection->executeQuery($sql, $params, $types)->fetchAllAssociative()
        );
    }

    public function get(int $id): array
    {
        $this->databaseSwitchService->switchBackToOriginalDatabase();
        $this->ensureInstallColumn();

        $row = $this->connection->fetchAssociative(
            'SELECT `id`, `app_host`, `db_host`, `db_name`, `db_port`, `db_user`, `db_driver`, `db_instance`, `instalation_status`
             FROM `databases`
             WHERE `id` = :id',
            ['id' => $id],
            ['id' => ParameterType::INTEGER]
        );

        if (!$row) {
            throw new NotFoundHttpException('Tenancy not found.');
        }

        return $this->normalizeRow($row);
    }

    public function discover(array $payload): array
    {
        $this->databaseSwitchService->switchBackToOriginalDatabase();
        $this->ensureInstallColumn();

        $data = $this->normalizePayload($payload);
        $existingId = $this->connection->fetchOne(
            'SELECT `id` FROM `databases` WHERE `app_host` = :app_host LIMIT 1',
            ['app_host' => $data['app_host']],
            ['app_host' => ParameterType::STRING]
        );

        if ($existingId) {
            $this->update((int) $existingId, $payload);

            return $this->get((int) $existingId);
        }

        $this->connection->executeStatement(
            'INSERT INTO `databases`
                (`app_host`, `db_host`, `db_name`, `db_port`, `db_user`, `db_password`, `db_driver`, `db_instance`, `instalation_status`)
             VALUES
                (:app_host, :db_host, :db_name, :db_port, :db_user, AES_ENCRYPT(:db_password, :tenancy_secret), :db_driver, :db_instance, :instalation_status)',
            [
                ...$data,
                'tenancy_secret' => $this->getTenancySecret(),
            ],
            [
                'app_host' => ParameterType::STRING,
                'db_host' => ParameterType::STRING,
                'db_name' => ParameterType::STRING,
                'db_port' => ParameterType::INTEGER,
                'db_user' => ParameterType::STRING,
                'db_password' => ParameterType::STRING,
                'db_driver' => ParameterType::STRING,
                'db_instance' => ParameterType::STRING,
                'instalation_status' => ParameterType::STRING,
                'tenancy_secret' => ParameterType::STRING,
            ]
        );

        return $this->get((int) $this->connection->lastInsertId());
    }

    public function update(int $id, array $payload): array
    {
        $this->databaseSwitchService->switchBackToOriginalDatabase();
        $this->ensureInstallColumn();
        $current = $this->get($id);
        $data = $this->normalizePayload($payload, $current, false);

        $sets = [
            '`app_host` = :app_host',
            '`db_host` = :db_host',
            '`db_name` = :db_name',
            '`db_port` = :db_port',
            '`db_user` = :db_user',
            '`db_driver` = :db_driver',
            '`db_instance` = :db_instance',
            '`instalation_status` = :instalation_status',
        ];
        $params = [
            ...$data,
            'id' => $id,
        ];
        $types = [
            'app_host' => ParameterType::STRING,
            'db_host' => ParameterType::STRING,
            'db_name' => ParameterType::STRING,
            'db_port' => ParameterType::INTEGER,
            'db_user' => ParameterType::STRING,
            'db_driver' => ParameterType::STRING,
            'db_instance' => ParameterType::STRING,
            'instalation_status' => ParameterType::STRING,
            'id' => ParameterType::INTEGER,
        ];

        if (array_key_exists('db_password', $payload) && trim((string) $payload['db_password']) !== '') {
            $sets[] = '`db_password` = AES_ENCRYPT(:db_password, :tenancy_secret)';
            $params['db_password'] = trim((string) $payload['db_password']);
            $params['tenancy_secret'] = $this->getTenancySecret();
            $types['db_password'] = ParameterType::STRING;
            $types['tenancy_secret'] = ParameterType::STRING;
        }

        $this->connection->executeStatement(
            sprintf(
                'UPDATE `databases` SET %s WHERE `id` = :id',
                implode(', ', $sets)
            ),
            $params,
            $types
        );

        return $this->get($id);
    }

    public function markPending(int $id): array
    {
        $this->databaseSwitchService->switchBackToOriginalDatabase();
        $this->ensureInstallColumn();

        $this->connection->executeStatement(
            'UPDATE `databases` SET `instalation_status` = "pending" WHERE `id` = :id',
            ['id' => $id],
            ['id' => ParameterType::INTEGER]
        );

        return $this->get($id);
    }

    private function ensureInstallColumn(): void
    {
        $exists = (int) $this->connection->fetchOne(
            'SELECT COUNT(*)
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = "databases"
               AND COLUMN_NAME = "instalation_status"'
        ) > 0;

        if ($exists) {
            return;
        }

        $this->connection->executeStatement(
            'ALTER TABLE `databases`
             ADD `instalation_status` ENUM("pending", "installing", "installed", "failed") NOT NULL DEFAULT "pending" AFTER `db_password`'
        );
        $this->connection->executeStatement(
            'UPDATE `databases` SET `instalation_status` = "installed" WHERE `instalation_status` = "pending"'
        );
    }

    private function normalizePayload(array $payload, array $current = [], bool $requirePassword = true): array
    {
        $data = [
            'app_host' => $this->requireDomain($payload['app_host'] ?? $payload['appHost'] ?? $current['appHost'] ?? ''),
            'db_host' => $this->requireText($payload['db_host'] ?? $payload['dbHost'] ?? $current['dbHost'] ?? '', 'db_host is required.'),
            'db_name' => $this->requireText($payload['db_name'] ?? $payload['dbName'] ?? $current['dbName'] ?? '', 'db_name is required.'),
            'db_port' => (int) ($payload['db_port'] ?? $payload['dbPort'] ?? $current['dbPort'] ?? 3306),
            'db_user' => $this->requireText($payload['db_user'] ?? $payload['dbUser'] ?? $current['dbUser'] ?? '', 'db_user is required.'),
            'db_driver' => $this->normalizeDriver($payload['db_driver'] ?? $payload['dbDriver'] ?? $current['dbDriver'] ?? 'pdo_mysql'),
            'db_instance' => trim((string) ($payload['db_instance'] ?? $payload['dbInstance'] ?? $current['dbInstance'] ?? '')),
            'instalation_status' => $this->normalizeStatus($payload['instalation_status'] ?? $payload['instalationStatus'] ?? $current['instalationStatus'] ?? 'pending'),
        ];

        if ($requirePassword) {
            $data['db_password'] = $this->requireText($payload['db_password'] ?? $payload['dbPassword'] ?? '', 'db_password is required.');
        }

        return $data;
    }

    private function normalizeRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'appHost' => (string) $row['app_host'],
            'dbHost' => (string) $row['db_host'],
            'dbName' => (string) $row['db_name'],
            'dbPort' => (int) $row['db_port'],
            'dbUser' => (string) $row['db_user'],
            'dbDriver' => (string) $row['db_driver'],
            'dbInstance' => (string) ($row['db_instance'] ?? ''),
            'instalationStatus' => (string) ($row['instalation_status'] ?? 'pending'),
        ];
    }

    private function requireDomain(mixed $value): string
    {
        $domain = strtolower($this->requireText($value, 'app_host is required.'));
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = preg_replace('#/.*$#', '', $domain);

        return trim((string) $domain);
    }

    private function requireText(mixed $value, string $message): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            throw new BadRequestHttpException($message);
        }

        return $text;
    }

    private function normalizeDriver(mixed $value): string
    {
        $driver = trim((string) $value) ?: 'pdo_mysql';
        if (!in_array($driver, ['pdo_mysql', 'pdo_sqlsrv'], true)) {
            throw new BadRequestHttpException('db_driver is invalid.');
        }

        return $driver;
    }

    private function normalizeStatus(mixed $value): string
    {
        $status = strtolower(trim((string) $value)) ?: 'pending';
        if (!in_array($status, self::INSTALLATION_STATUSES, true)) {
            throw new BadRequestHttpException('instalation_status is invalid.');
        }

        return $status;
    }

    private function getTenancySecret(): string
    {
        return (string) ($_ENV['TENANCY_SECRET'] ?? $_SERVER['TENANCY_SECRET'] ?? getenv('TENANCY_SECRET') ?: '');
    }
}
