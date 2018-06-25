<?php

namespace React\Tests\MySQL;

use React\MySQL\QueryResult;

class NoResultQueryTest extends BaseTestCase
{
    public function setUp()
    {
        $loop = \React\EventLoop\Factory::create();
        $connection = $this->createConnection($loop);

        // re-create test "book" table
        $connection->query('DROP TABLE IF EXISTS book');
        $connection->query($this->getDataTable());

        $connection->quit();
        $loop->run();
    }

    public function testUpdateSimpleNonExistentReportsNoAffectedRows()
    {
        $loop = \React\EventLoop\Factory::create();
        $connection = $this->createConnection($loop);

        $connection->query('update book set created=999 where id=999')->then(function (QueryResult $command) {
            $this->assertEquals(0, $command->affectedRows);
        });

        $connection->quit();
        $loop->run();
    }

    public function testInsertSimpleReportsFirstInsertId()
    {
        $loop = \React\EventLoop\Factory::create();
        $connection = $this->createConnection($loop);

        $connection->query("insert into book (`name`) values ('foo')")->then(function (QueryResult $command) {
            $this->assertEquals(1, $command->affectedRows);
            $this->assertEquals(1, $command->insertId);
        });

        $connection->quit();
        $loop->run();
    }

    public function testUpdateSimpleReportsAffectedRow()
    {
        $loop = \React\EventLoop\Factory::create();
        $connection = $this->createConnection($loop);

        $connection->query("insert into book (`name`) values ('foo')");
        $connection->query('update book set created=999 where id=1')->then(function (QueryResult $command) {
            $this->assertEquals(1, $command->affectedRows);
        });

        $connection->quit();
        $loop->run();
    }
}
