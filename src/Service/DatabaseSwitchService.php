<?php

namespace ControleOnline\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySqlPlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Doctrine\DBAL\Types\Type;
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

        // AleMac // 25/11/25
        // $this->switchDatabase($this->getDbData($domain));
        // Só troca o banco se existir tenant para aquele domínio.
        $dbData = $this->getDbData($domain);

        if (!$dbData) {
            // Nenhum tenant → NÃO trocar banco
            return;
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
        $sql = 'SELECT db_host, db_name, db_port, db_user, db_driver, db_instance, 
            AES_DECRYPT(db_password, :tenancy_secret) AS db_password
            FROM `databases` WHERE app_host = :app_host';

        $statement = $this->connection->executeQuery(
            $sql,
            [
                'app_host' => $domain,
                'tenancy_secret' => $_ENV['TENANCY_SECRET']
            ],
            ['app_host' => Type::getType('string'), 'tenancy_secret' => Type::getType('string')]
        );

        $result = $statement->fetchAssociative();

        // AleMac // 25/11/25
        // Se a consulta não encontrar domínio, 
        // retorna false em vez de tentar acessar
        if (!$result) {
            return false; // nenhum tenant encontrado
        }
        //

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

  
}
