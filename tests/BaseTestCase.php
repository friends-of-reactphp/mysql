<?php

namespace React\Tests\MySQL;

class BaseTestCase extends \PHPUnit_Extensions_Database_TestCase
{
    private static $pdo;
    private $conn;

    protected function getConnection()
    {
        if ($this->conn === null) {
            if (self::$pdo == null) {
                $conf = $this->getConnectionOptions();
                $dsn = 'mysql:host=' . $conf['host'] . ';port=' . $conf['port'] . ';dbname=' . $conf['dbname'];
                self::$pdo = new \PDO($dsn, $conf['user'], $conf['passwd']);
            }

            self::$pdo->query('
                CREATE TABLE IF NOT EXISTS `book` (
                    `id`      INT(11)      NOT NULL AUTO_INCREMENT,
                    `name`    VARCHAR(255) NOT NULL,
                    `isbn`    VARCHAR(255) NULL,
                    `author`  VARCHAR(255) NULL,
                    `created` INT(11)      NULL,
                    PRIMARY KEY (`id`)
                )
            ');

            $this->conn = $this->createDefaultDBConnection(self::$pdo, ':memory:');
        }

        return $this->conn;
    }

    protected function getDataSet()
    {
        return new \PHPUnit_Extensions_Database_DataSet_YamlDataSet(__DIR__ . '/dataset.yaml');
    }

    protected function getConnectionOptions()
    {
        return [
            'host'   => getenv('DB_HOST'),
            'port'   => (int)getenv('DB_PORT'),
            'dbname' => getenv('DB_DBNAME'),
            'user'   => getenv('DB_USER'),
            'passwd' => getenv('DB_PASSWD'),
        ];
    }
}
