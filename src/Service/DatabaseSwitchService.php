<?php

namespace ControleOnline\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\DBAL\Platforms\SQLServer2012Platform;
use Doctrine\DBAL\Platforms\MySqlPlatform;
use InvalidArgumentException;

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
        $this->switchDatabase($this->getDbData($domain));
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
            $this->connection->getEventManager()
        );

        $this->connection->connect();
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
        $sql = 'SELECT db_host, db_name, db_port, db_user, db_driver, db_instance, 
            AES_DECRYPT(db_password, :tenancy_secret) AS db_password
            FROM `databases` WHERE app_host = :app_host';

        $statement = $this->connection->executeQuery(
            $sql,
            [
                'app_host' => $domain,
                'tenancy_secret' => $_ENV['TENENCY_SECRET']
            ],
            ['app_host' => \PDO::PARAM_STR, 'tenancy_secret' => \PDO::PARAM_STR]
        );

        $result = $statement->fetchAssociative();
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

        // Construa a instância do driver
        return new $driverClass();
    }

    /**
     * @param string $db_driver
     * @return MySqlPlatform|SQLServer2012Platform
     */
    private function getPlatform($db_driver)
    {
        switch ($db_driver) {
            case 'pdo_mysql':
                return new MySqlPlatform();
            case 'pdo_sqlsrv':
                return new SQLServer2012Platform();
            default:
                throw new InvalidArgumentException('Driver not supported: ' . $db_driver);
        }
    }

    /**
     * @param Request $request
     * @return string
     */
    public function getDomain(Request $request)
    {

        $domain = preg_replace("/[^a-zA-Z0-9.:_-]/", "", str_replace(
            ['https://', 'http://'],
            '',
            $request->get(
                'app-domain',
                $request->headers->get(
                    'app-domain',
                    $request->headers->get(
                        'referer',
                        $_SERVER['HTTP_HOST']
                    )
                )
            )
        ));

        if (!$domain)
            throw new InvalidArgumentException('Please define header or get param "app-domain"', 301);
        return $domain;
    }
}
