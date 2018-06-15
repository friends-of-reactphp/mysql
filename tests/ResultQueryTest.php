<?php

namespace React\Tests\MySQL;

use React\MySQL\Protocal\Constants;

class ResultQueryTest extends BaseTestCase
{
    public function testSelectStaticText()
    {
        $loop = \React\EventLoop\Factory::create();

        $connection = new \React\MySQL\Connection($loop, $this->getConnectionOptions());
        $connection->connect(function () {});

        $connection->query('select \'foo\'', function ($command, $conn) {
            $this->assertEquals(false, $command->hasError());

            $this->assertCount(1, $command->resultRows);
            $this->assertCount(1, $command->resultRows[0]);
            $this->assertSame('foo', reset($command->resultRows[0]));

            $this->assertInstanceOf('React\MySQL\Connection', $conn);
        });

        $connection->close();
        $loop->run();
    }

    public function provideValuesThatWillBeReturnedAsIs()
    {
        return array_map(function ($e) { return array($e); }, array(
            'foo',
            'hello?',
            'FööBär',
            'pile of 💩',
            '<>&--\\\'";',
            "\0\1\2\3\4\5\6\7\8\xff",
            '',
            null
        ));
    }

    public function provideValuesThatWillBeConvertedToString()
    {
        return array(
            array(1, '1'),
            array(1.5, '1.5'),
            array(true, '1'),
            array(false, '0')
        );
    }

    /**
     * @dataProvider provideValuesThatWillBeReturnedAsIs
     */
    public function testSelectStaticValueWillBeReturnedAsIs($value)
    {
        $loop = \React\EventLoop\Factory::create();

        $connection = new \React\MySQL\Connection($loop, $this->getConnectionOptions());
        $connection->connect(function () {});

        $expected = $value;

        $connection->query('select ?', function ($command, $conn) use ($expected) {
            $this->assertEquals(false, $command->hasError());

            $this->assertCount(1, $command->resultRows);
            $this->assertCount(1, $command->resultRows[0]);
            $this->assertSame($expected, reset($command->resultRows[0]));

            $this->assertInstanceOf('React\MySQL\Connection', $conn);
        }, $value);

        $connection->close();
        $loop->run();
    }

    /**
     * @dataProvider provideValuesThatWillBeConvertedToString
     */
    public function testSelectStaticValueWillBeConvertedToString($value, $expected)
    {
        $loop = \React\EventLoop\Factory::create();

        $connection = new \React\MySQL\Connection($loop, $this->getConnectionOptions());
        $connection->connect(function () {});

        $connection->query('select ?', function ($command, $conn) use ($expected) {
            $this->assertEquals(false, $command->hasError());

            $this->assertCount(1, $command->resultRows);
            $this->assertCount(1, $command->resultRows[0]);
            $this->assertSame($expected, reset($command->resultRows[0]));

            $this->assertInstanceOf('React\MySQL\Connection', $conn);
        }, $value);

        $connection->close();
        $loop->run();
    }

    public function testSelectStaticTextWithQuestionMark()
    {
        $loop = \React\EventLoop\Factory::create();

        $connection = new \React\MySQL\Connection($loop, $this->getConnectionOptions());
        $connection->connect(function () {});

        $connection->query('select \'hello?\'', function ($command, $conn) {
            $this->assertEquals(false, $command->hasError());

            $this->assertCount(1, $command->resultRows);
            $this->assertCount(1, $command->resultRows[0]);
            $this->assertEquals('hello?', reset($command->resultRows[0]));

            $this->assertInstanceOf('React\MySQL\Connection', $conn);
        });

        $connection->close();
        $loop->run();
    }

    public function testSelectLongStaticTextHasTypeStringWithValidLength()
    {
        $loop = \React\EventLoop\Factory::create();

        $connection = new \React\MySQL\Connection($loop, $this->getConnectionOptions());
        $connection->connect(function () {});

        $length = 40000;

        $connection->query('SELECT ?', function ($command, $conn) use ($length) {
            $this->assertEquals(false, $command->hasError());

            $this->assertCount(1, $command->resultFields);
            $this->assertEquals($length * 3, $command->resultFields[0]['length']);
            $this->assertSame(Constants::FIELD_TYPE_VAR_STRING, $command->resultFields[0]['type']);

            $this->assertInstanceOf('React\MySQL\Connection', $conn);
        }, str_repeat('.', $length));

        $connection->close();
        $loop->run();
    }

    public function testSelectStaticTextWithEmptyLabel()
    {
        $loop = \React\EventLoop\Factory::create();

        $connection = new \React\MySQL\Connection($loop, $this->getConnectionOptions());
        $connection->connect(function () {});

        $connection->query('select \'foo\' as ``', function ($command, $conn) {
            $this->assertEquals(false, $command->hasError());

            $this->assertCount(1, $command->resultRows);
            $this->assertCount(1, $command->resultRows[0]);
            $this->assertSame('foo', reset($command->resultRows[0]));
            $this->assertSame('', key($command->resultRows[0]));

            $this->assertCount(1, $command->resultFields);
            $this->assertSame('', $command->resultFields[0]['name']);

            $this->assertInstanceOf('React\MySQL\Connection', $conn);
        });

        $connection->close();
        $loop->run();
    }

    public function testSelectStaticNullHasTypeNull()
    {
        $loop = \React\EventLoop\Factory::create();

        $connection = new \React\MySQL\Connection($loop, $this->getConnectionOptions());
        $connection->connect(function () {});

        $connection->query('select null', function ($command, $conn) {
            $this->assertEquals(false, $command->hasError());

            $this->assertCount(1, $command->resultRows);
            $this->assertCount(1, $command->resultRows[0]);
            $this->assertNull(reset($command->resultRows[0]));

            $this->assertCount(1, $command->resultFields);
            $this->assertSame(Constants::FIELD_TYPE_NULL, $command->resultFields[0]['type']);

            $this->assertInstanceOf('React\MySQL\Connection', $conn);
        });

        $connection->close();
        $loop->run();
    }

    public function testSelectStaticTextTwoRows()
    {
        $loop = \React\EventLoop\Factory::create();

        $connection = new \React\MySQL\Connection($loop, $this->getConnectionOptions());
        $connection->connect(function () {});

        $connection->query('select "foo" UNION select "bar"', function ($command, $conn) {
            $this->assertEquals(false, $command->hasError());

            $this->assertCount(2, $command->resultRows);
            $this->assertCount(1, $command->resultRows[0]);

            $this->assertSame('foo', reset($command->resultRows[0]));
            $this->assertSame('bar', reset($command->resultRows[1]));

            $this->assertInstanceOf('React\MySQL\Connection', $conn);
        });

        $connection->close();
        $loop->run();
    }

    public function testSelectStaticTextTwoRowsWithNullHasTypeString()
    {
        $loop = \React\EventLoop\Factory::create();

        $connection = new \React\MySQL\Connection($loop, $this->getConnectionOptions());
        $connection->connect(function () {});

        $connection->query('select "foo" UNION select null', function ($command, $conn) {
            $this->assertEquals(false, $command->hasError());

            $this->assertCount(2, $command->resultRows);
            $this->assertCount(1, $command->resultRows[0]);

            $this->assertSame('foo', reset($command->resultRows[0]));
            $this->assertNull(reset($command->resultRows[1]));

            $this->assertCount(1, $command->resultFields);
            $this->assertSame(Constants::FIELD_TYPE_VAR_STRING, $command->resultFields[0]['type']);

            $this->assertInstanceOf('React\MySQL\Connection', $conn);
        });

        $connection->close();
        $loop->run();
    }

    public function testSelectStaticIntegerTwoRowsWithNullHasTypeLongButReturnsIntAsString()
    {
        $loop = \React\EventLoop\Factory::create();

        $connection = new \React\MySQL\Connection($loop, $this->getConnectionOptions());
        $connection->connect(function () {});

        $connection->query('select 0 UNION select null', function ($command, $conn) {
            $this->assertEquals(false, $command->hasError());

            $this->assertCount(2, $command->resultRows);
            $this->assertCount(1, $command->resultRows[0]);

            $this->assertSame('0', reset($command->resultRows[0]));
            $this->assertNull(reset($command->resultRows[1]));

            $this->assertCount(1, $command->resultFields);
            $this->assertSame(Constants::FIELD_TYPE_LONGLONG, $command->resultFields[0]['type']);

            $this->assertInstanceOf('React\MySQL\Connection', $conn);
        });

        $connection->close();
        $loop->run();
    }

    public function testSelectStaticTextTwoRowsWithIntegerHasTypeString()
    {
        $loop = \React\EventLoop\Factory::create();

        $connection = new \React\MySQL\Connection($loop, $this->getConnectionOptions());
        $connection->connect(function () {});

        $connection->query('select "foo" UNION select 1', function ($command, $conn) {
            $this->assertEquals(false, $command->hasError());

            $this->assertCount(2, $command->resultRows);
            $this->assertCount(1, $command->resultRows[0]);

            $this->assertSame('foo', reset($command->resultRows[0]));
            $this->assertSame('1', reset($command->resultRows[1]));

            $this->assertCount(1, $command->resultFields);
            $this->assertSame(Constants::FIELD_TYPE_VAR_STRING, $command->resultFields[0]['type']);

            $this->assertInstanceOf('React\MySQL\Connection', $conn);
        });

        $connection->close();
        $loop->run();
    }

    public function testSelectStaticTextTwoRowsWithEmptyRow()
    {
        $loop = \React\EventLoop\Factory::create();

        $connection = new \React\MySQL\Connection($loop, $this->getConnectionOptions());
        $connection->connect(function () {});

        $connection->query('select "foo" UNION select ""', function ($command, $conn) {
            $this->assertEquals(false, $command->hasError());

            $this->assertCount(2, $command->resultRows);
            $this->assertCount(1, $command->resultRows[0]);

            $this->assertSame('foo', reset($command->resultRows[0]));
            $this->assertSame('', reset($command->resultRows[1]));

            $this->assertInstanceOf('React\MySQL\Connection', $conn);
        });

        $connection->close();
        $loop->run();
    }

    public function testSelectStaticTextNoRows()
    {
        $loop = \React\EventLoop\Factory::create();

        $connection = new \React\MySQL\Connection($loop, $this->getConnectionOptions());
        $connection->connect(function () {});

        $connection->query('select "foo" LIMIT 0', function ($command, $conn) {
            $this->assertEquals(false, $command->hasError());

            $this->assertCount(0, $command->resultRows);

            $this->assertCount(1, $command->resultFields);
            $this->assertSame('foo', $command->resultFields[0]['name']);

            $this->assertInstanceOf('React\MySQL\Connection', $conn);
        });

        $connection->close();
        $loop->run();
    }

    public function testSelectStaticTextTwoColumns()
    {
        $loop = \React\EventLoop\Factory::create();

        $connection = new \React\MySQL\Connection($loop, $this->getConnectionOptions());
        $connection->connect(function () {});

        $connection->query('select "foo","bar"', function ($command, $conn) {
            $this->assertEquals(false, $command->hasError());

            $this->assertCount(1, $command->resultRows);
            $this->assertCount(2, $command->resultRows[0]);

            $this->assertSame('foo', reset($command->resultRows[0]));
            $this->assertSame('bar', next($command->resultRows[0]));

            $this->assertInstanceOf('React\MySQL\Connection', $conn);
        });

        $connection->close();
        $loop->run();
    }

    public function testSelectStaticTextTwoColumnsWithOneEmptyColumn()
    {
        $loop = \React\EventLoop\Factory::create();

        $connection = new \React\MySQL\Connection($loop, $this->getConnectionOptions());
        $connection->connect(function () {});

        $connection->query('select "foo",""', function ($command, $conn) {
            $this->assertEquals(false, $command->hasError());

            $this->assertCount(1, $command->resultRows);
            $this->assertCount(2, $command->resultRows[0]);

            $this->assertSame('foo', reset($command->resultRows[0]));
            $this->assertSame('', next($command->resultRows[0]));

            $this->assertInstanceOf('React\MySQL\Connection', $conn);
        });

        $connection->close();
        $loop->run();
    }

    public function testSelectStaticTextTwoColumnsWithBothEmpty()
    {
        $loop = \React\EventLoop\Factory::create();

        $connection = new \React\MySQL\Connection($loop, $this->getConnectionOptions());
        $connection->connect(function () {});

        $connection->query('select \'\' as `first`, \'\' as `second`', function ($command, $conn) {
            $this->assertEquals(false, $command->hasError());

            $this->assertCount(1, $command->resultRows);
            $this->assertCount(2, $command->resultRows[0]);
            $this->assertSame(array('', ''), array_values($command->resultRows[0]));

            $this->assertCount(2, $command->resultFields);
            $this->assertSame(Constants::FIELD_TYPE_VAR_STRING, $command->resultFields[0]['type']);
            $this->assertSame(Constants::FIELD_TYPE_VAR_STRING, $command->resultFields[1]['type']);

            $this->assertInstanceOf('React\MySQL\Connection', $conn);
        });

        $connection->close();
        $loop->run();
    }

    public function testSelectStaticTextTwoColumnsWithSameNameOverwritesValue()
    {
        $loop = \React\EventLoop\Factory::create();

        $connection = new \React\MySQL\Connection($loop, $this->getConnectionOptions());
        $connection->connect(function () {});

        $connection->query('select "foo" as `col`,"bar" as `col`', function ($command, $conn) {
            $this->assertEquals(false, $command->hasError());

            $this->assertCount(1, $command->resultRows);
            $this->assertCount(1, $command->resultRows[0]);

            $this->assertSame('bar', reset($command->resultRows[0]));

            $this->assertCount(2, $command->resultFields);
            $this->assertSame('col', $command->resultFields[0]['name']);
            $this->assertSame('col', $command->resultFields[1]['name']);

            $this->assertInstanceOf('React\MySQL\Connection', $conn);
        });

        $connection->close();
        $loop->run();
    }

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

    public function testInvalidSelectShouldFail()
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

    public function testInvalidMultiStatementsShouldFailToPreventSqlInjections()
    {
        $loop = \React\EventLoop\Factory::create();

        $connection = new \React\MySQL\Connection($loop, $this->getConnectionOptions());
        $connection->connect(function () {});

        $connection->query('select 1;select 2;', function ($command, $conn) {
            $this->assertEquals(true, $command->hasError());
            $this->assertContains("You have an error in your SQL syntax", $command->getError()->getMessage());
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
