<?php

namespace React\Tests\MySQL;

use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\MySQL\ConnectionInterface;
use React\MySQL\Factory;

class BaseTestCase extends TestCase
{
    protected function getConnectionOptions($debug = false)
    {
        // can be controlled through ENV or by changing defaults in phpunit.xml
        return [
            'host'   => getenv('DB_HOST'),
            'port'   => (int)getenv('DB_PORT'),
            'dbname' => getenv('DB_DBNAME'),
            'user'   => getenv('DB_USER'),
            'passwd' => getenv('DB_PASSWD'),
        ] + ($debug ? ['debug' => true] : []);
    }

    protected function getConnectionString($params = array())
    {
        $parts = $params + $this->getConnectionOptions();

        return rawurlencode($parts['user']) . ':' . rawurlencode($parts['passwd']) . '@' . $parts['host'] . ':' . $parts['port'] . '/' . rawurlencode($parts['dbname']);
    }

    /**
     * @param LoopInterface $loop
     * @return ConnectionInterface
     */
    protected function createConnection(LoopInterface $loop)
    {
        $factory = new Factory($loop);
        $promise = $factory->createConnection($this->getConnectionString());

        return \Clue\React\Block\await($promise, $loop, 10.0);
    }

    protected function getDataTable()
    {
        return <<<SQL
CREATE TABLE `book` (
    `id`      INT(11)      NOT NULL AUTO_INCREMENT,
    `name`    VARCHAR(255) NOT NULL,
    `isbn`    VARCHAR(255) NULL,
    `author`  VARCHAR(255) NULL,
    `created` INT(11)      NULL,
    PRIMARY KEY (`id`)
)
SQL;
    }

    protected function expectCallableOnce()
    {
        $mock = $this->getMockBuilder('stdClass')->setMethods(array('__invoke'))->getMock();
        $mock->expects($this->once())->method('__invoke');

        return $mock;
    }

    protected function expectCallableOnceWith($value)
    {
        $mock = $this->getMockBuilder('stdClass')->setMethods(array('__invoke'))->getMock();
        $mock->expects($this->once())->method('__invoke')->with($value);

        return $mock;
    }

    protected function expectCallableNever()
    {
        $mock = $this->getMockBuilder('stdClass')->setMethods(array('__invoke'))->getMock();
        $mock->expects($this->never())->method('__invoke');

        return $mock;
    }
}
