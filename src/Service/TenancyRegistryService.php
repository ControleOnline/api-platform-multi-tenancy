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

        $status = trim((string) ($filters['instalation_status'] ?? ''));
        if ($status !== '') {
            $status = $this->normalizeStatus($status);
            $where[] = $this->columnExpression('instalation_status') . ' = :status';
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
            'SELECT `id` FROM `databases` WHERE `app_host` = :app_host LIMIT 1',
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
            '`instalation_status` = :instalation_status',
        ];
        $params = [
            'app_host' => $data['app_host'],
            'instalation_status' => $data['instalation_status'],
            'id' => $id,
        ];
        $types = [
            'app_host' => ParameterType::STRING,
            'instalation_status' => ParameterType::STRING,
            'id' => ParameterType::INTEGER,
        ];

        if ($this->hasNormalizedConnectionSchema()) {
            $sets[] = '`database_connection_id` = :database_connection_id';
            $params['database_connection_id'] = $this->findOrCreateDatabaseConnection(
                $data,
                array_key_exists('db_password', $payload) ? trim((string) $payload['db_password']) : null
            );
            $types['database_connection_id'] = ParameterType::INTEGER;
        } else {
            $sets = array_merge($sets, [
                '`db_host` = :db_host',
                '`db_name` = :db_name',
                '`db_port` = :db_port',
                '`db_user` = :db_user',
                '`db_driver` = :db_driver',
                '`db_instance` = :db_instance',
            ]);
            $params += [
                'db_host' => $data['db_host'],
                'db_name' => $data['db_name'],
                'db_port' => $data['db_port'],
                'db_user' => $data['db_user'],
                'db_driver' => $data['db_driver'],
                'db_instance' => $data['db_instance'],
            ];
            $types += [
                'db_host' => ParameterType::STRING,
                'db_name' => ParameterType::STRING,
                'db_port' => ParameterType::INTEGER,
                'db_user' => ParameterType::STRING,
                'db_driver' => ParameterType::STRING,
                'db_instance' => $data['db_instance'] === null ? ParameterType::NULL : ParameterType::STRING,
            ];

            if (array_key_exists('db_password', $payload) && trim((string) $payload['db_password']) !== '') {
                $sets[] = '`db_password` = AES_ENCRYPT(:db_password, :tenancy_secret)';
                $params['db_password'] = trim((string) $payload['db_password']);
                $params['tenancy_secret'] = $this->getTenancySecret();
                $types['db_password'] = ParameterType::STRING;
                $types['tenancy_secret'] = ParameterType::STRING;
            }
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
             ADD `instalation_status` ENUM("pending", "installing", "installed", "failed") NOT NULL DEFAULT "pending"'
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
            'db_instance' => $this->normalizeNullableText($payload['db_instance'] ?? $payload['dbInstance'] ?? $current['dbInstance'] ?? null),
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
            'databaseConnectionId' => (int) ($row['database_connection_id'] ?? 0),
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

    private function buildTenancySelectSql(): string
    {
        if ($this->hasNormalizedConnectionSchema()) {
            return 'SELECT
                    databases.`id`,
                    databases.`database_connection_id`,
                    databases.`app_host`,
                    database_connections.`db_host`,
                    database_connections.`db_name`,
                    database_connections.`db_port`,
                    database_connections.`db_user`,
                    database_connections.`db_driver`,
                    database_connections.`db_instance`,
                    databases.`instalation_status`
                FROM `databases`
                INNER JOIN `database_connections`
                    ON database_connections.`id` = databases.`database_connection_id`';
        }

        return 'SELECT
                `id`,
                NULL AS `database_connection_id`,
                `app_host`,
                `db_host`,
                `db_name`,
                `db_port`,
                `db_user`,
                `db_driver`,
                `db_instance`,
                `instalation_status`
            FROM `databases`';
    }

    private function columnExpression(string $columnName): string
    {
        if ($this->hasNormalizedConnectionSchema()) {
            return match ($columnName) {
                'id', 'app_host', 'database_connection_id', 'instalation_status' => 'databases.`' . $columnName . '`',
                default => 'database_connections.`' . $columnName . '`',
            };
        }

        return '`' . $columnName . '`';
    }

    private function buildInsertSql(array $data): string
    {
        if ($this->hasNormalizedConnectionSchema()) {
            return 'INSERT INTO `databases`
                    (`app_host`, `database_connection_id`, `instalation_status`)
                 VALUES
                    (:app_host, :database_connection_id, :instalation_status)';
        }

        return 'INSERT INTO `databases`
                (`app_host`, `db_host`, `db_name`, `db_port`, `db_user`, `db_password`, `db_driver`, `db_instance`, `instalation_status`)
             VALUES
                (:app_host, :db_host, :db_name, :db_port, :db_user, AES_ENCRYPT(:db_password, :tenancy_secret), :db_driver, :db_instance, :instalation_status)';
    }

    private function buildInsertParams(array $data): array
    {
        if ($this->hasNormalizedConnectionSchema()) {
            return [
                'app_host' => $data['app_host'],
                'database_connection_id' => $this->findOrCreateDatabaseConnection($data, $data['db_password'] ?? null),
                'instalation_status' => $data['instalation_status'],
            ];
        }

        return [
            ...$data,
            'tenancy_secret' => $this->getTenancySecret(),
        ];
    }

    private function buildInsertTypes(array $data): array
    {
        if ($this->hasNormalizedConnectionSchema()) {
            return [
                'app_host' => ParameterType::STRING,
                'database_connection_id' => ParameterType::INTEGER,
                'instalation_status' => ParameterType::STRING,
            ];
        }

        return [
            'app_host' => ParameterType::STRING,
            'db_host' => ParameterType::STRING,
            'db_name' => ParameterType::STRING,
            'db_port' => ParameterType::INTEGER,
            'db_user' => ParameterType::STRING,
            'db_password' => ParameterType::STRING,
            'db_driver' => ParameterType::STRING,
            'db_instance' => $data['db_instance'] === null ? ParameterType::NULL : ParameterType::STRING,
            'instalation_status' => ParameterType::STRING,
            'tenancy_secret' => ParameterType::STRING,
        ];
    }

    private function findOrCreateDatabaseConnection(array $data, ?string $password = null): int
    {
        $this->ensureConnectionSchema();

        $params = [
            'connection_hash' => $this->buildConnectionHash($data),
            'db_driver' => $data['db_driver'],
            'db_host' => $data['db_host'],
            'db_name' => $data['db_name'],
            'db_user' => $data['db_user'],
            'db_port' => $data['db_port'],
            'db_instance' => $data['db_instance'] ?? '',
        ];
        $types = [
            'connection_hash' => ParameterType::STRING,
            'db_driver' => ParameterType::STRING,
            'db_host' => ParameterType::STRING,
            'db_name' => ParameterType::STRING,
            'db_user' => ParameterType::STRING,
            'db_port' => ParameterType::INTEGER,
            'db_instance' => ParameterType::STRING,
        ];

        $existingId = $this->connection->fetchOne(
            'SELECT `id`
             FROM `database_connections`
             WHERE `connection_hash` = :connection_hash
             LIMIT 1',
            $params,
            $types
        );

        if ($existingId) {
            if ($password !== null && trim($password) !== '') {
                $this->connection->executeStatement(
                    'UPDATE `database_connections`
                     SET `db_password` = AES_ENCRYPT(:db_password, :tenancy_secret),
                         `updated_at` = NOW()
                     WHERE `id` = :id',
                    [
                        'db_password' => trim($password),
                        'tenancy_secret' => $this->getTenancySecret(),
                        'id' => (int) $existingId,
                    ],
                    [
                        'db_password' => ParameterType::STRING,
                        'tenancy_secret' => ParameterType::STRING,
                        'id' => ParameterType::INTEGER,
                    ]
                );
            }

            return (int) $existingId;
        }

        $password = trim((string) $password);
        if ($password === '') {
            throw new BadRequestHttpException('db_password is required for a new database connection.');
        }

        $this->connection->executeStatement(
            'INSERT INTO `database_connections` (
                `db_driver`,
                `db_instance`,
                `db_port`,
                `db_host`,
                `db_name`,
                `db_user`,
                `db_password`,
                `connection_hash`
             ) VALUES (
                :db_driver,
                :db_instance,
                :db_port,
                :db_host,
                :db_name,
                :db_user,
                AES_ENCRYPT(:db_password, :tenancy_secret),
                :connection_hash
             )',
            $params + [
                'db_password' => $password,
                'tenancy_secret' => $this->getTenancySecret(),
            ],
            $types + [
                'db_password' => ParameterType::STRING,
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

    private function ensureConnectionSchema(): void
    {
        if (!$this->hasNormalizedConnectionSchema()) {
            throw new BadRequestHttpException('database_connections schema is not available.');
        }
    }

    private function hasNormalizedConnectionSchema(): bool
    {
        return $this->tableExists('database_connections')
            && $this->columnExists('databases', 'database_connection_id');
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
            throw new BadRequestHttpException('instalation_status is invalid.');
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
