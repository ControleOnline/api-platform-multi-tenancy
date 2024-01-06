<?php

namespace ControleOnline\EventListener;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Doctrine\DBAL\Platforms\MySqlPlatform;
use Exception;
use InvalidArgumentException;

class DatabaseSwitchService
{
    private $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function switchDatabaseByDomain($domain)
    {
        $dbData =     $this->getDbData($domain);
        $this->connection->close();
        $this->connection->__construct(
            $dbData,
            $this->getDriverClass($dbData),
            $this->connection->getConfiguration(),
            $this->connection->getEventManager()
        );
        $this->connection->connect();
        return  $dbData;
    }

    private function getDbData($domain)
    {
        $params = $this->connection->getParams();
        $sql = 'SELECT db_host, db_name, db_port, db_user, db_driver, db_instance, db_password FROM `databases` WHERE app_host = :app_host';
        $statement = $this->connection->executeQuery($sql, ['app_host' => $domain]);

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

    private function getDriverClass($dbData)
    {
        $driverClass = null;

        // Verifique o valor do parâmetro 'driver'
        switch ($dbData['driver']) {
            case 'pdo_mysql':
                $driverClass = \Doctrine\DBAL\Driver\PDO\MySql\Driver::class;
                break;
            case 'pdo_sqlsrv':
                $driverClass = \Doctrine\DBAL\Driver\PDO\SQLSrv\Driver::class;
                break;
                // Adicione outros casos conforme necessário para suportar outros drivers
            default:
                throw new InvalidArgumentException('Driver not supported: ' . $dbData['driver']);
        }

        // Construa a instância do driver
        return new $driverClass();
    }

    private function getPlatform($db_driver)
    {
        switch ($db_driver) {
            case 'pdo_mysql':
                return new MySqlPlatform();
            case 'pdo_sqlsrv':
                return new SQLServerPlatform();
                // Adicione outros casos conforme necessário para suportar outros drivers
            default:
                throw new InvalidArgumentException('Driver not supported: ' . $db_driver);
        }
    }

    public function getDomain(Request $request)
    {

        $domain = preg_replace("/[^a-zA-Z0-9.:]/", "", str_replace(
            ['https://', 'http://'],
            '',
            $request->get(
                'app-domain',
                $request->headers->get(
                    'app-domain',
                    $request->headers->get(
                        'referer',
                        null
                    )
                )
            )
        ));

        if (!$domain)
            throw new InvalidArgumentException('Please define header or get param "app-domain"', 301);
        return $domain;
    }
}
