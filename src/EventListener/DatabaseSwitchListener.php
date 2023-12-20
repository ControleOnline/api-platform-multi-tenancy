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

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function onKernelRequest(RequestEvent $event)
    {
        $this->domain = $this->getDomain($event->getRequest());
        $params = $this->getDbData();

        $this->connection->close();
        $this->connection->__construct(
            $params,
            $this->connection->getDriver(),
            $this->connection->getConfiguration(),
            $this->connection->getEventManager()
        );
        $this->connection->connect();
    }

    private function getDbData()
    {
        $params = $this->connection->getParams();
        $sql = 'SELECT db_host, db_name, db_port, db_user, db_password FROM `databases` WHERE app_host = :app_host';
        $statement = $this->connection->executeQuery($sql, ['app_host' => $this->domain]);
        $result = $statement->fetchAssociative();
        $params['host'] = $result['db_host'];
        $params['port'] = $result['db_port'];
        $params['dbname'] = $result['db_name'];
        $params['user'] = $result['db_user'];
        $params['password'] = $result['db_password'];

        return $params;
    }

    private function getDomain(Request $request)
    {

        $this->domain = $request->get(
            'app-domain',
            $request->headers->get(
                'app-domain',
                $request->headers->get(
                    'domain',
                    $request->headers->get(
                        'Domain',
                        null
                    )
                )
            )
        );

        if (!$this->domain)
            throw new Exception('Please define header or get param "app-domain"', 301);
    }
}
