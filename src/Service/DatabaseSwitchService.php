<?php

namespace ControleOnline\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Doctrine\DBAL\Platforms\MySqlPlatform;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\ParameterType;
use InvalidArgumentException;

class DatabaseSwitchService
{
    private $connection;
    private static $originalDbParams;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
        if (!self::$originalDbParams) {
            self::$originalDbParams = $connection->getParams();
        }
    }

    public function switchDatabaseByDomain($domain)
    {
        $this->switchDatabase($this->getDbData($domain));
    }

    public function switchBackToOriginalDatabase()
    {
        $this->switchDatabase(self::$originalDbParams);
        return true;
    }

    private function switchDatabase($dbData)
    {
        if ($this->connection->isConnected()) {
            $this->connection->close();
        }

        $this->connection = DriverManager::getConnection(
            $dbData,
            $this->connection->getConfiguration()
        );
    }

    private function getDbData($domain)
    {
        $this->switchBackToOriginalDatabase();
        $params = $this->connection->getParams();

        $sql = 'SELECT db_host, db_name, db_port, db_user, db_driver, db_instance, 
            AES_DECRYPT(db_password, :tenancy_secret) AS db_password
            FROM `databases` WHERE app_host = :app_host';

        try {
            $statement = $this->connection->executeQuery(
                $sql,
                [
                    'app_host' => $domain,
                    'tenancy_secret' => $_ENV['TENENCY_SECRET']
                ],
                [
                    'app_host' => ParameterType::STRING,
                    'tenancy_secret' => ParameterType::STRING
                ]
            );
        } catch (\Doctrine\DBAL\Exception $e) {
            throw new \RuntimeException('Error connecting to the database: ' . $e->getMessage());
        }

        $result = $statement->fetchAssociative();
        if (!$result) {
            throw new InvalidArgumentException("No database configuration found for domain: $domain");
        }

        $params['platform'] = $this->getPlatform($result['db_driver']);
        $params['host'] = $result['db_host'];
        $params['port'] = $result['db_port'];
        $params['dbname'] = $result['db_name'];
        $params['user'] = $result['db_user'];
        $params['password'] = $result['db_password'];
        $params['driver'] = $result['db_driver'];
        $params['instancename'] = $result['db_instance'];
        return $params;
    }

    public function getAllDomains()
    {
        $this->switchBackToOriginalDatabase();
        $sql = 'SELECT app_host FROM `databases`';
        $statement = $this->connection->executeQuery($sql);
        $results = $statement->fetchAllAssociative();
        $domains = array_column($results, 'app_host');
        return $domains;
    }

    private function getDriverClass($dbData)
    {
        $driverClass = null;
        switch ($dbData['driver']) {
            case 'pdo_mysql':
                $driverClass = \Doctrine\DBAL\Driver\PDO\MySQL\Driver::class;
                break;
            case 'pdo_sqlsrv':
                $driverClass = \Doctrine\DBAL\Driver\PDO\SQLSrv\Driver::class;
                break;
            default:
                throw new InvalidArgumentException('Unsupported driver: ' . $dbData['driver']);
        }

        return new $driverClass();
    }

    private function getPlatform($db_driver)
    {
        switch ($db_driver) {
            case 'pdo_mysql':
                return new MySqlPlatform();
            case 'pdo_sqlsrv':
                return new SQLServerPlatform();
            default:
                throw new InvalidArgumentException('Unsupported driver: ' . $db_driver);
        }
    }
}
