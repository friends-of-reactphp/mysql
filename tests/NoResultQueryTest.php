<?php

namespace React\Tests\MySQL;

class NoResultQueryTest extends BaseTestCase
{
    public function setUp()
    {
        $loop = \React\EventLoop\Factory::create();

        $connection = new \React\MySQL\Connection($loop, $this->getConnectionOptions());
        $connection->connect(function () {});

        // re-create test "book" table
        $connection->query('DROP TABLE IF EXISTS book');
        $connection->query($this->getDataTable());

        $connection->close();
        $loop->run();
    }

    public function testUpdateSimpleNonExistentReportsNoAffectedRows()
    {
        $loop = \React\EventLoop\Factory::create();

        $connection = new \React\MySQL\Connection($loop, $this->getConnectionOptions());
        $connection->connect(function () {});

        $connection->query('update book set created=999 where id=999', function ($command, $conn) {
            $this->assertEquals(false, $command->hasError());
            $this->assertEquals(0, $command->affectedRows);
        });

        $connection->close();
        $loop->run();
    }

    public function testInsertSimpleReportsFirstInsertId()
    {
        $loop = \React\EventLoop\Factory::create();

        $connection = new \React\MySQL\Connection($loop, $this->getConnectionOptions());
        $connection->connect(function () {});

        $connection->query("insert into book (`name`) values ('foo')", function ($command, $conn) {
            $this->assertEquals(false, $command->hasError());
            $this->assertEquals(1, $command->affectedRows);
            $this->assertEquals(1, $command->insertId);
        });

        $connection->close();
        $loop->run();
    }

    public function testUpdateSimpleReportsAffectedRow()
    {
        $loop = \React\EventLoop\Factory::create();

        $connection = new \React\MySQL\Connection($loop, $this->getConnectionOptions());
        $connection->connect(function () {});

        $connection->query("insert into book (`name`) values ('foo')");
        $connection->query('update book set created=999 where id=1', function ($command, $conn) {
            $this->assertEquals(false, $command->hasError());
            $this->assertEquals(1, $command->affectedRows);
        });

        $connection->close();
        $loop->run();
    }
}
