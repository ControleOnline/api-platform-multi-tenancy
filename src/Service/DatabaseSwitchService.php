<?php

/*
 * Contract imported from AGENTS.md
 * ## Escopo
 * - Modulo de multi-tenancy da API.
 * - Cobre troca de banco, mudanca de tenant, listeners e comandos de migracao por tenant.
 *
 * ## Quando usar
 * - Prompts sobre tenant, database switching, migracao de tenants e isolamento por base.
 *
 * ## Limites
 * - Alteracoes aqui sao sensiveis e impactam toda a API.
 * - Nao misturar regra de dominio de negocio com a infraestrutura de tenant.
 */


namespace ControleOnline\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySqlPlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Doctrine\DBAL\Types\Type;
use InvalidArgumentException;
use RuntimeException;

class DatabaseSwitchService
{
    /**
     * @var Connection
     */
    private $connection;
    /**
     * @var array
     */
    private static $originalDbParams;


    /**
     * DatabaseSwitchService constructor.
     * @param Connection $connection
     */
    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
        if (!self::$originalDbParams)
            self::$originalDbParams = $connection->getParams();
    }

    /* @param string $domain
     * @throws \Doctrine\DBAL\Driver\Exception
     * @throws \Doctrine\DBAL\Exception
     */
    public function switchDatabaseByDomain($domain)
    {

        $dbData = $this->getDbData($domain);

        if (!$dbData) {
            throw new RuntimeException(sprintf('Tenant "%s" not found.', $domain));
        }

        $this->switchDatabase($dbData);

    }

    /**
     * @param string $domain
     * @return bool
     */
    public function switchBackToOriginalDatabase()
    {
        $this->switchDatabase(self::$originalDbParams);
    }

    /**
     * @param array $dbData
     */
    private function switchDatabase($dbData)
    {
        if ($this->connection->isConnected())
            $this->connection->close();

        $this->connection->__construct(
            $dbData,
            $this->getDriverClass($dbData),
            $this->connection->getConfiguration(),
            //$this->connection->getEventManager()
        );

        //$this->connection->connect();
    }

    /**
     * @param string $domain
     * @return array
     * @throws \Doctrine\DBAL\Driver\Exception
     * @throws \Doctrine\DBAL\Exception
     * @throws \Doctrine\DBAL\Driver\Exception
     */
    private function getDbData($domain)
    {
        $this->switchBackToOriginalDatabase();
        $params = $this->connection->getParams();
        if ($this->hasNormalizedConnectionSchema()) {
            $sql = 'SELECT
                    database_connections.db_host,
                    database_connections.db_name,
                    database_connections.db_port,
                    database_connections.db_user,
                    database_connections.db_driver,
                    database_connections.db_instance,
                    AES_DECRYPT(database_connections.db_password, :tenancy_secret) AS db_password
                FROM `databases`
                INNER JOIN `database_connections`
                    ON database_connections.id = databases.database_connection_id
                WHERE databases.app_host = :app_host';
        } else {
            $sql = 'SELECT db_host, db_name, db_port, db_user, db_driver, db_instance,
                AES_DECRYPT(db_password, :tenancy_secret) AS db_password
                FROM `databases` WHERE app_host = :app_host';
        }

        $statement = $this->connection->executeQuery(
            $sql,
            [
                'app_host' => $domain,
                'tenancy_secret' => $_ENV['TENANCY_SECRET']
            ],
            ['app_host' => Type::getType('string'), 'tenancy_secret' => Type::getType('string')]
        );

        $result = $statement->fetchAssociative();

        if (!$result) {
            return false;
        }

        $params['platform'] = $this->getPlatform($result['db_driver']);
        $params['host'] = $result['db_host'];
        $params['port'] = $result['db_port'];
        $params['dbname'] = $result['db_name'];
        $params['user'] = $result['db_user'];
        $params['password'] = $result['db_password'];
        $params['driver'] = $result['db_driver'];
        $params['instancename'] = $result['db_instance'];
        return  $params;
    }

    /**
     * @return array
     * @throws \Doctrine\DBAL\Driver\Exception
     * @throws \Doctrine\DBAL\Exception
     */
    public function getAllDomains()
    {
        $this->switchBackToOriginalDatabase();
        $sql = 'SELECT app_host FROM `databases`';
        $statement = $this->connection->executeQuery($sql);
        $results = $statement->fetchAllAssociative();
        $domains = array_column($results, 'app_host');
        return $domains;
    }

    /**
     * @param string $domain
     * @return array|false
     * @throws \Doctrine\DBAL\Driver\Exception
     * @throws \Doctrine\DBAL\Exception
     */
    public function getTenantConnectionInfo($domain)
    {
        $currentParams = $this->connection->getParams();
        $this->switchBackToOriginalDatabase();

        try {
            $statement = $this->connection->executeQuery(
                $this->hasNormalizedConnectionSchema()
                    ? 'SELECT
                        databases.app_host,
                        database_connections.db_host,
                        database_connections.db_name,
                        database_connections.db_port,
                        database_connections.db_driver,
                        database_connections.db_instance
                     FROM `databases`
                     INNER JOIN `database_connections`
                        ON database_connections.id = databases.database_connection_id
                     WHERE databases.app_host = :app_host
                     LIMIT 1'
                    : 'SELECT app_host, db_host, db_name, db_port, db_driver, db_instance
                     FROM `databases`
                     WHERE app_host = :app_host
                     LIMIT 1',
                ['app_host' => $domain],
                ['app_host' => Type::getType('string')]
            );

            $result = $statement->fetchAssociative();
        } finally {
            $this->switchDatabase($currentParams);
        }

        if (!$result) {
            return false;
        }

        return [
            'app_host' => $result['app_host'] ?? $domain,
            'db_host' => $result['db_host'] ?? null,
            'db_name' => $result['db_name'] ?? null,
            'db_port' => $result['db_port'] ?? null,
            'db_driver' => $result['db_driver'] ?? null,
            'db_instance' => $result['db_instance'] ?? null,
        ];
    }

    /**
     * @param array $dbData
     * @return mixed
     */
    private function getDriverClass($dbData)
    {
        $driverClass = null;
        switch ($dbData['driver']) {
            case 'pdo_mysql':
                $driverClass = \Doctrine\DBAL\Driver\PDO\MySql\Driver::class;
                break;
            case 'pdo_sqlsrv':
                $driverClass = \Doctrine\DBAL\Driver\PDO\SQLSrv\Driver::class;
                break;
            default:
                throw new InvalidArgumentException('Driver not supported: ' . $dbData['driver']);
        }

        return new $driverClass();
    }

    /**
     * @param string $db_driver
     * @return MySqlPlatform|SQLServerPlatform
     */
    private function getPlatform($db_driver)
    {
        switch ($db_driver) {
            case 'pdo_mysql':
                return new MySqlPlatform();
            case 'pdo_sqlsrv':
                return new SQLServerPlatform();
            default:
                throw new InvalidArgumentException('Driver not supported: ' . $db_driver);
        }
    }

    private function hasNormalizedConnectionSchema(): bool
    {
        return $this->tableExists('database_connections')
            && $this->columnExists('databases', 'database_connection_id');
    }

    private function tableExists(string $tableName): bool
    {
        try {
            return (int) $this->connection->fetchOne(
                'SELECT COUNT(*)
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?',
                [$tableName]
            ) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function columnExists(string $tableName, string $columnName): bool
    {
        try {
            return (int) $this->connection->fetchOne(
                'SELECT COUNT(*)
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                   AND COLUMN_NAME = ?',
                [$tableName, $columnName]
            ) > 0;
        } catch (\Throwable) {
            return false;
        }
    }
}
