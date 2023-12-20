<?php

namespace ControleOnline\EventListener;

use Doctrine\DBAL\Connection;
use Exception;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Doctrine\DBAL\Platforms\MySqlPlatform;


class DatabaseSwitchListener
{
    private $connection;
    private $domain;
    private static $tenency_params;
    

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }



    public function onKernelRequest(RequestEvent $event)
    {                
            if (!self::$tenency_params){
                $this->getDomain($event->getRequest());
                $this->getDbData();     
            }

            $this->connection->close();
            $this->connection->__construct(
                self::$tenency_params,
                $this->getDriverClass(),
                $this->connection->getConfiguration(),
                $this->connection->getEventManager()
            );
            $this->connection->connect();
        
    }

    private function getDbData()
    {
        if (self::$tenency_params)
            return;
        
        $params = $this->connection->getParams();
        $sql = 'SELECT db_host, db_name, db_port, db_user, db_driver, db_instance, db_password FROM `databases` WHERE app_host = :app_host';
        $statement = $this->connection->executeQuery($sql, ['app_host' => $this->domain]);
        $result = $statement->fetchAssociative();
        $params['host'] = $result['db_host'];
        $params['port'] = $result['db_port'];
        $params['dbname'] = $result['db_name'];
        $params['user'] = $result['db_user'];
        $params['password'] = $result['db_password'];
        $params['driver'] = $result['db_driver'];
        $params['instancename'] = $result['db_instance'];        
        self::$tenency_params =  $params;        
    }

    private function getDriverClass()
    {
        $driverClass = null;

        // Verifique o valor do parâmetro 'driver'
        switch (self::$tenency_params['driver']) {
            case 'pdo_mysql':
                $driverClass = \Doctrine\DBAL\Driver\PDO\MySql\Driver::class;
                $this->connection->getDatabasePlatform()->setPlatform(new MySqlPlatform());
                break;
            case 'pdo_sqlsrv':
                $driverClass = \Doctrine\DBAL\Driver\PDO\SQLSrv\Driver::class;
                $this->connection->getDatabasePlatform()->setPlatform(new SQLServerPlatform());
                break;
                // Adicione outros casos conforme necessário para suportar outros drivers
            default:
                throw new \InvalidArgumentException('Driver not supported: ' . self::$tenency_params['driver']);
        }

        // Construa a instância do driver
        return   new $driverClass;
    }
    
    private function getDomain(Request $request)
    {

        $this->domain = preg_replace("/[^a-zA-Z0-9.]/", "",str_replace('https://','',$request->get(
            'app-domain',
            $request->headers->get(
                'app-domain',
                $request->headers->get(
                    'referer',
                    null
                )
            )
        )));

        if (!$this->domain)
            throw new Exception('Please define header or get param "app-domain"', 301);
    }
}
