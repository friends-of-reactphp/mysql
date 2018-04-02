<?php

namespace React\Tests\MySQL;

class ResultQueryTest extends BaseTestCase
{
    public function testSimpleSelect()
    {
        $loop = \React\EventLoop\Factory::create();

        $connection = new \React\MySQL\Connection($loop, $this->getConnectionOptions());
        $connection->connect(function () {});

        // re-create test "book" table
        $connection->query('DROP TABLE IF EXISTS book');
        $connection->query($this->getDataTable());
        $connection->query("insert into book (`name`) values ('foo')");
        $connection->query("insert into book (`name`) values ('bar')");

        $connection->query('select * from book', function ($command, $conn) {
            $this->assertEquals(false, $command->hasError());
            $this->assertCount(2, $command->resultRows);
            $this->assertInstanceOf('React\MySQL\Connection', $conn);
        });

        $connection->close();
        $loop->run();
    }

    public function testInvalidSelect()
    {
        $loop = \React\EventLoop\Factory::create();

        $options = $this->getConnectionOptions();
        $db = $options['dbname'];
        $connection = new \React\MySQL\Connection($loop, $options);
        $connection->connect(function () {});

        $connection->query('select * from invalid_table', function ($command, $conn) use ($db) {
            $this->assertEquals(true, $command->hasError());
            $this->assertEquals("Table '$db.invalid_table' doesn't exist", $command->getError()->getMessage());
        });

        $connection->close();
        $loop->run();
    }

    public function testEventSelect()
    {
        $this->expectOutputString('result.result.results.end.');
        $loop = \React\EventLoop\Factory::create();

        $connection = new \React\MySQL\Connection($loop, $this->getConnectionOptions());
        $connection->connect(function () {});

        // re-create test "book" table
        $connection->query('DROP TABLE IF EXISTS book');
        $connection->query($this->getDataTable());
        $connection->query("insert into book (`name`) values ('foo')");
        $connection->query("insert into book (`name`) values ('bar')");

        $command = $connection->query('select * from book');
        $command->on('results', function ($results, $command, $conn) {
            $this->assertEquals(2, count($results));
            $this->assertInstanceOf('React\MySQL\Commands\QueryCommand', $command);
            $this->assertInstanceOf('React\MySQL\Connection', $conn);
            echo 'results.';
        });
        $command->on('result', function ($result, $command, $conn) {
                $this->assertArrayHasKey('id', $result);
                $this->assertInstanceOf('React\MySQL\Commands\QueryCommand', $command);
                $this->assertInstanceOf('React\MySQL\Connection', $conn);
                echo 'result.';
            })
            ->on('end', function ($command, $conn) {
                $this->assertInstanceOf('React\MySQL\Commands\QueryCommand', $command);
                $this->assertInstanceOf('React\MySQL\Connection', $conn);
                echo 'end.';
            });

        $connection->close();
        $loop->run();
    }

    public function testSelectAfterDelay()
    {
        $loop = \React\EventLoop\Factory::create();

        $connection = new \React\MySQL\Connection($loop, $this->getConnectionOptions());

        $callback = function () use ($connection) {
            $connection->query('select 1+1', function ($command, $conn) {
                $this->assertEquals(false, $command->hasError());
                $this->assertEquals([['1+1' => 2]], $command->resultRows);
            });
            $connection->close();
        };

        $timeoutCb = function () use ($loop) {
            $loop->stop();
            $this->fail('Test timeout');
        };

        $connection->connect(function ($err, $conn) use ($callback, $loop, $timeoutCb) {
            $this->assertEquals(null, $err);
            $loop->addTimer(0.1, $callback);

            $timeout = $loop->addTimer(1, $timeoutCb);
            $conn->on('close', function () use ($loop, $timeout) {
                $loop->cancelTimer($timeout);
            });
        });

        $loop->run();
    }
}
