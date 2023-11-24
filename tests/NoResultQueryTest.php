<?php

namespace React\Tests\Mysql;

use React\EventLoop\Loop;
use React\Mysql\MysqlClient;
use React\Mysql\MysqlResult;

class NoResultQueryTest extends BaseTestCase
{
    /**
     * @before
     */
    public function setUpDataTable()
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

        $connection->query('update book set created=999 where id=999')->then(function (MysqlResult $command) {
            $this->assertEquals(0, $command->affectedRows);
        });

        $connection->quit();
        Loop::run();
    }

    public function testInsertSimpleReportsFirstInsertId()
    {
        $connection = $this->createConnection(Loop::get());

        $connection->query("insert into book (`name`) values ('foo')")->then(function (MysqlResult $command) {
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
        $connection->query('update book set created=999 where id=1')->then(function (MysqlResult $command) {
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

        $connection->query($sql)->then(function (MysqlResult $command) {
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


    public function testQuitWithAnyAuthWillQuitWithoutRunning()
    {
        $this->expectOutputString('closed.');

        $uri = 'mysql://random:pass@host';
        $connection = new MysqlClient($uri);

        $connection->quit()->then(function () {
            echo 'closed.';
        });
    }

    public function testPingWithValidAuthWillRunUntilQuitAfterPing()
    {
        $this->expectOutputString('closed.');

        $uri = $this->getConnectionString();
        $connection = new MysqlClient($uri);

        $connection->ping();

        $connection->quit()->then(function () {
            echo 'closed.';
        });

        Loop::run();
    }

    public function testPingAndQuitWillFulfillPingBeforeQuitBeforeCloseEvent()
    {
        $this->expectOutputString('ping.quit.close.');

        $uri = $this->getConnectionString();
        $connection = new MysqlClient($uri);

        $connection->on('close', function () {
            echo 'close.';
        });

        $connection->ping()->then(function () {
            echo 'ping.';
        });

        $connection->quit()->then(function () {
            echo 'quit.';
        });

        Loop::run();
    }

    public function testPingWithValidAuthWillRunUntilIdleTimerAfterPingEvenWithoutQuit()
    {
        $uri = $this->getConnectionString();
        $connection = new MysqlClient($uri);

        $connection->on('close', $this->expectCallableNever());

        $connection->ping();

        Loop::run();
    }

    public function testPingWithInvalidAuthWillRejectPingButWillNotEmitErrorOrClose()
    {
        $uri = $this->getConnectionString(['passwd' => 'invalidpass']);
        $connection = new MysqlClient($uri);

        $connection->on('error', $this->expectCallableNever());
        $connection->on('close', $this->expectCallableNever());

        $connection->ping()->then(null, $this->expectCallableOnce());

        Loop::run();
    }

    public function testPingWithValidAuthWillPingBeforeQuitButNotAfter()
    {
        $this->expectOutputString('rejected.ping.closed.');

        $uri = $this->getConnectionString();
        $connection = new MysqlClient($uri);

        $connection->ping()->then(function () {
            echo 'ping.';
        });

        $connection->quit()->then(function () {
            echo 'closed.';
        });

        $connection->ping()->then(function () {
            echo 'never reached';
        }, function () {
            echo 'rejected.';
        });

        Loop::run();
    }
}
