<?php

namespace ControleOnline\EventListener;

use Doctrine\DBAL\Connection;
use Exception;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpFoundation\Request;


class DatabaseSwitchListener
{
    private $connection;
    private $domain;
    private static $tenency_params;
    private $tenency_connection;

    public function __construct(Connection $connection)
    {
        $this->tenency_connection = $connection;           
    }



    public function onKernelRequest(RequestEvent $event)
    {                
        if (!$this->connection){
            $this->getDomain($event->getRequest());
            $this->connection = clone $this->tenency_connection;
            $this->connection->close();
            $this->connection->__construct(
                self::$tenency_params,
                $this->connection->getDriver(),
                $this->connection->getConfiguration(),
                $this->connection->getEventManager()
            );
            $this->connection->connect();
        }
    }

    private function getDbData()
    {
        if (self::$tenency_params)
            return self::$tenency_params;

        
        $params = $this->tenency_connection->getParams();
        $sql = 'SELECT db_host, db_name, db_port, db_user, db_driver, db_instance, db_password FROM `databases` WHERE app_host = :app_host';
        $statement = $this->tenency_connection->executeQuery($sql, ['app_host' => $this->domain]);
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

    public function __destruct (){        
        $this->tenency_connection->close();
    }
    
}
