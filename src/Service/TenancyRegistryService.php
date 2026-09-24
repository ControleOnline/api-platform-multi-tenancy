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
            $where[] = sprintf(
                '(%s LIKE :search OR %s LIKE :search OR %s LIKE :search OR %s LIKE :search)',
                $this->columnExpression('app_host'),
                $this->columnExpression('db_host'),
                $this->columnExpression('db_name'),
                $this->columnExpression('db_user')
            );
            $params['search'] = '%' . $search . '%';
            $types['search'] = ParameterType::STRING;
        }

        $status = trim((string) ($filters['installation_status'] ?? ''));
        if ($status !== '') {
            $status = $this->normalizeStatus($status);
            $where[] = $this->columnExpression('installation_status') . ' = :status';
            $params['status'] = $status;
            $types['status'] = ParameterType::STRING;
        }

        $sql = $this->buildTenancySelectSql();

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY ' . $this->columnExpression('id') . ' DESC LIMIT 500';

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
            $this->buildTenancySelectSql() . ' WHERE ' . $this->columnExpression('id') . ' = :id',
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
            'SELECT `id` FROM `tenancies` WHERE `app_host` = :app_host LIMIT 1',
            ['app_host' => $data['app_host']],
            ['app_host' => ParameterType::STRING]
        );

        if ($existingId) {
            $this->update((int) $existingId, $payload);

            return $this->get((int) $existingId);
        }

        $this->connection->executeStatement(
            $this->buildInsertSql($data),
            $this->buildInsertParams($data),
            $this->buildInsertTypes($data)
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
            '`installation_status` = :installation_status',
        ];
        $params = [
            'app_host' => $data['app_host'],
            'installation_status' => $data['installation_status'],
            'id' => $id,
        ];
        $types = [
            'app_host' => ParameterType::STRING,
            'installation_status' => ParameterType::STRING,
            'id' => ParameterType::INTEGER,
        ];

        $sets[] = '`server_id` = :server_id';
        $params['server_id'] = $this->findOrCreateConnectionServer(
            $data,
            array_key_exists('db_password', $payload) ? trim((string) $payload['db_password']) : null
        );
        $types['server_id'] = ParameterType::INTEGER;

        $this->connection->executeStatement(
            sprintf(
                'UPDATE `tenancies` SET %s WHERE `id` = :id',
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
            'UPDATE `tenancies` SET `installation_status` = "pending" WHERE `id` = :id',
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
               AND TABLE_NAME = "tenancies"
               AND COLUMN_NAME = "installation_status"'
        ) > 0;

        if ($exists) {
            return;
        }

        $this->connection->executeStatement(
            'ALTER TABLE `tenancies`
             ADD `installation_status` ENUM("pending", "installing", "installed", "failed") NOT NULL DEFAULT "pending"'
        );
        $this->connection->executeStatement(
            'UPDATE `tenancies` SET `installation_status` = "installed" WHERE `installation_status` = "pending"'
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
            'db_instance' => $this->normalizeNullableText($payload['db_instance'] ?? $payload['dbInstance'] ?? $current['dbInstance'] ?? null),
            'installation_status' => $this->normalizeStatus($payload['installation_status'] ?? $payload['installationStatus'] ?? $current['installationStatus'] ?? 'pending'),
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
            'serverId' => (int) ($row['server_id'] ?? 0),
            'appHost' => (string) $row['app_host'],
            'dbHost' => (string) $row['db_host'],
            'dbName' => (string) $row['db_name'],
            'dbPort' => (int) $row['db_port'],
            'dbUser' => (string) $row['db_user'],
            'dbDriver' => (string) $row['db_driver'],
            'dbInstance' => (string) ($row['db_instance'] ?? ''),
            'installationStatus' => (string) ($row['installation_status'] ?? 'pending'),
        ];
    }

    private function buildTenancySelectSql(): string
    {
        return 'SELECT
                tenancies.`id`,
                tenancies.`server_id`,
                tenancies.`app_host`,
                servers.`host` AS `db_host`,
                servers.`db_name`,
                servers.`port` AS `db_port`,
                servers.`user` AS `db_user`,
                servers.`driver` AS `db_driver`,
                servers.`db_instance`,
                tenancies.`installation_status`
            FROM `tenancies`
            INNER JOIN `servers`
                ON servers.`id` = tenancies.`server_id`';
    }

    private function columnExpression(string $columnName): string
    {
        return match ($columnName) {
            'id', 'app_host', 'server_id', 'installation_status' => 'tenancies.`' . $columnName . '`',
            'db_host' => 'servers.`host`',
            'db_port' => 'servers.`port`',
            'db_user' => 'servers.`user`',
            'db_driver' => 'servers.`driver`',
            default => 'servers.`' . $columnName . '`',
        };
    }

    private function buildInsertSql(array $data): string
    {
        return 'INSERT INTO `tenancies`
                (`app_host`, `server_id`, `installation_status`)
             VALUES
                (:app_host, :server_id, :installation_status)';
    }

    private function buildInsertParams(array $data): array
    {
        return [
            'app_host' => $data['app_host'],
            'server_id' => $this->findOrCreateConnectionServer($data, $data['db_password'] ?? null),
            'installation_status' => $data['installation_status'],
        ];
    }

    private function buildInsertTypes(array $data): array
    {
        return [
            'app_host' => ParameterType::STRING,
            'server_id' => ParameterType::INTEGER,
            'installation_status' => ParameterType::STRING,
        ];
    }

    private function findOrCreateConnectionServer(array $data, ?string $password = null): int
    {
        $this->ensureServerConnectionSchema();
        $connectionHash = $this->buildConnectionHash($data);

        $params = [
            'connection_hash' => $connectionHash,
            'driver' => $data['db_driver'],
            'host' => $data['db_host'],
            'db_name' => $data['db_name'],
            'db_instance' => $data['db_instance'] ?? null,
            'port' => $data['db_port'],
            'user' => $data['db_user'],
        ];
        $types = [
            'connection_hash' => ParameterType::STRING,
            'driver' => ParameterType::STRING,
            'host' => ParameterType::STRING,
            'db_name' => ParameterType::STRING,
            'db_instance' => $params['db_instance'] === null ? ParameterType::NULL : ParameterType::STRING,
            'port' => ParameterType::INTEGER,
            'user' => ParameterType::STRING,
        ];

        $existingId = $this->connection->fetchOne(
            'SELECT `id`
             FROM `servers`
             WHERE `connection_hash` = :connection_hash
             LIMIT 1',
            ['connection_hash' => $params['connection_hash']],
            ['connection_hash' => ParameterType::STRING]
        );

        if ($existingId) {
            if ($password !== null && trim($password) !== '') {
                $this->connection->executeStatement(
                    'UPDATE `servers`
                     SET `password` = AES_ENCRYPT(:password, :tenancy_secret)
                     WHERE `id` = :id',
                    [
                        'password' => trim($password),
                        'tenancy_secret' => $this->getTenancySecret(),
                        'id' => (int) $existingId,
                    ],
                    [
                        'password' => ParameterType::STRING,
                        'tenancy_secret' => ParameterType::STRING,
                        'id' => ParameterType::INTEGER,
                    ]
                );
            }

            return (int) $existingId;
        }

        $password = trim((string) $password);
        if ($password === '') {
            throw new BadRequestHttpException('db_password is required for a new server connection.');
        }

        $this->connection->executeStatement(
            'INSERT INTO `servers` (
                `driver`,
                `port`,
                `host`,
                `db_name`,
                `db_instance`,
                `user`,
                `password`,
                `connection_hash`
             ) VALUES (
                :driver,
                :port,
                :host,
                :db_name,
                :db_instance,
                :user,
                AES_ENCRYPT(:password, :tenancy_secret),
                :connection_hash
             )',
            $params + [
                'password' => $password,
                'tenancy_secret' => $this->getTenancySecret(),
            ],
            $types + [
                'password' => ParameterType::STRING,
                'tenancy_secret' => ParameterType::STRING,
            ]
        );

        return (int) $this->connection->lastInsertId();
    }

    private function buildConnectionHash(array $data): string
    {
        return hash('sha256', implode('|', [
            $data['db_driver'],
            $data['db_host'],
            $data['db_name'],
            $data['db_user'],
            (string) $data['db_port'],
            (string) ($data['db_instance'] ?? ''),
        ]));
    }

    private function ensureServerConnectionSchema(): void
    {
        if (!$this->hasServerConnectionSchema()) {
            throw new BadRequestHttpException('servers connection schema is not available.');
        }
    }

    private function hasServerConnectionSchema(): bool
    {
        return $this->tableExists('servers')
            && $this->columnExists('tenancies', 'server_id')
            && $this->columnExists('servers', 'db_name')
            && $this->columnExists('servers', 'connection_hash');
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

    private function normalizeNullableText(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function normalizeStatus(mixed $value): string
    {
        $status = strtolower(trim((string) $value)) ?: 'pending';
        if (!in_array($status, self::INSTALLATION_STATUSES, true)) {
            throw new BadRequestHttpException('installation_status is invalid.');
        }

        return $status;
    }

    private function getTenancySecret(): string
    {
        $secret = (string) ($_ENV['TENANCY_SECRET'] ?? $_SERVER['TENANCY_SECRET'] ?? getenv('TENANCY_SECRET') ?: '');
        if ($secret === '') {
            throw new BadRequestHttpException('TENANCY_SECRET is not configured.');
        }

        return $secret;
    }
}
