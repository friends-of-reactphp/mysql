<?php

namespace React\Tests\MySQL;

use React\EventLoop\Loop;
use React\MySQL\QueryResult;

class NoResultQueryTest extends BaseTestCase
{
    public function setUp()
    {
        $connection = $this->createConnection(Loop::get());

        // re-create test "book" table
        $connection->query('DROP TABLE IF EXISTS book');
        $connection->query($this->getDataTable());

        $connection->quit();
        Loop::run();
    }

    public function testUpdateSimpleNonExistentReportsNoAffectedRows()
    {
        $connection = $this->createConnection(Loop::get());

        $connection->query('update book set created=999 where id=999')->then(function (QueryResult $command) {
            $this->assertEquals(0, $command->affectedRows);
        });

        $connection->quit();
        Loop::run();
    }

    public function testInsertSimpleReportsFirstInsertId()
    {
        $connection = $this->createConnection(Loop::get());

        $connection->query("insert into book (`name`) values ('foo')")->then(function (QueryResult $command) {
            $this->assertEquals(1, $command->affectedRows);
            $this->assertEquals(1, $command->insertId);
        });

        $connection->quit();
        Loop::run();
    }

    public function testUpdateSimpleReportsAffectedRow()
    {
        $connection = $this->createConnection(Loop::get());

        $connection->query("insert into book (`name`) values ('foo')");
        $connection->query('update book set created=999 where id=1')->then(function (QueryResult $command) {
            $this->assertEquals(1, $command->affectedRows);
        });

        $connection->quit();
        Loop::run();
    }

    public function testCreateTableAgainWillAddWarning()
    {
        $connection = $this->createConnection(Loop::get());

        $sql = '
CREATE TABLE IF NOT EXISTS `book` (
    `id`      INT(11)      NOT NULL AUTO_INCREMENT,
    `name`    VARCHAR(255) NOT NULL,
    `isbn`    VARCHAR(255) NULL,
    `author`  VARCHAR(255) NULL,
    `created` INT(11)      NULL,
    PRIMARY KEY (`id`)
)';

        $connection->query($sql)->then(function (QueryResult $command) {
            $this->assertEquals(1, $command->warningCount);
        });

        $connection->quit();
        Loop::run();
    }

    public function testPingMultipleWillBeExecutedInSameOrderTheyAreEnqueuedFromHandlers()
    {
        $this->expectOutputString('123');

        $connection = $this->createConnection(Loop::get());

        $connection->ping()->then(function () use ($connection) {
            echo '1';

            $connection->ping()->then(function () use ($connection) {
                echo '3';
                $connection->quit();
            });
        });
        $connection->ping()->then(function () {
            echo '2';
        });

        Loop::run();
    }
}
