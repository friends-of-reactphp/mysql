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
                self::$pdo = new \PDO($GLOBALS['db_dsn'], $GLOBALS['db_user'], $GLOBALS['db_passwd']);
            }

            self::$pdo->query('
                CREATE TABLE IF NOT EXISTS `book` (
                    `id`      INT(11)      NOT NULL,
                    `name`    VARCHAR(255) NOT NULL,
                    `isbn`    VARCHAR(255) NOT NULL,
                    `author`  VARCHAR(255) NOT NULL,
                    `created` INT(11)      NOT NULL,
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
            'dbname' => $GLOBALS['db_dbname'],
            'user'   => $GLOBALS['db_user'],
            'passwd' => $GLOBALS['db_passwd'],
        ];
    }
}
