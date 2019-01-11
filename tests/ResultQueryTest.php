<?php

namespace React\Tests\MySQL;

use React\MySQL\Io\Constants;
use React\MySQL\QueryResult;
use React\MySQL\Factory;

class ResultQueryTest extends BaseTestCase
{
    public function testSelectStaticText()
    {
        $loop = \React\EventLoop\Factory::create();
        $connection = $this->createConnection($loop);

        $connection->query('select \'foo\'')->then(function (QueryResult $command) {
            $this->assertCount(1, $command->resultRows);
            $this->assertCount(1, $command->resultRows[0]);
            $this->assertSame('foo', reset($command->resultRows[0]));

            $this->assertInstanceOf('React\MySQL\Connection', $conn);
        });

        $connection->quit();
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
        $connection = $this->createConnection($loop);

        $expected = $value;

        $connection->query('select ?', [$value])->then(function (QueryResult $command) use ($expected) {
            $this->assertCount(1, $command->resultRows);
            $this->assertCount(1, $command->resultRows[0]);
            $this->assertSame($expected, reset($command->resultRows[0]));
        });

        $connection->quit();
        $loop->run();
    }

    /**
     * @dataProvider provideValuesThatWillBeConvertedToString
     */
    public function testSelectStaticValueWillBeConvertedToString($value, $expected)
    {
        $loop = \React\EventLoop\Factory::create();
        $connection = $this->createConnection($loop);

        $connection->query('select ?', [$value])->then(function (QueryResult $command) use ($expected) {
            $this->assertCount(1, $command->resultRows);
            $this->assertCount(1, $command->resultRows[0]);
            $this->assertSame($expected, reset($command->resultRows[0]));
        });

        $connection->quit();
        $loop->run();
    }

    public function testSelectStaticTextWithQuestionMark()
    {
        $loop = \React\EventLoop\Factory::create();
        $connection = $this->createConnection($loop);

        $connection->query('select \'hello?\'')->then(function (QueryResult $command) {
            $this->assertCount(1, $command->resultRows);
            $this->assertCount(1, $command->resultRows[0]);
            $this->assertEquals('hello?', reset($command->resultRows[0]));
        });

        $connection->quit();
        $loop->run();
    }

    public function testSelectLongStaticTextHasTypeStringWithValidLength()
    {
        $loop = \React\EventLoop\Factory::create();
        $connection = $this->createConnection($loop);

        $length = 40000;
        $value = str_repeat('.', $length);

        $connection->query('SELECT ?', [$value])->then(function (QueryResult $command) use ($length) {
            $this->assertCount(1, $command->resultFields);
            $this->assertEquals($length * 3, $command->resultFields[0]['length']);
            $this->assertSame(Constants::FIELD_TYPE_VAR_STRING, $command->resultFields[0]['type']);
        });

        $connection->quit();
        $loop->run();
    }

    public function testSelectStaticTextWithEmptyLabel()
    {
        $loop = \React\EventLoop\Factory::create();
        $connection = $this->createConnection($loop);

        $connection->query('select \'foo\' as ``')->then(function (QueryResult $command) {
            $this->assertCount(1, $command->resultRows);
            $this->assertCount(1, $command->resultRows[0]);
            $this->assertSame('foo', reset($command->resultRows[0]));
            $this->assertSame('', key($command->resultRows[0]));

            $this->assertCount(1, $command->resultFields);
            $this->assertSame('', $command->resultFields[0]['name']);
        });

        $connection->quit();
        $loop->run();
    }

    public function testSelectStaticNullHasTypeNull()
    {
        $loop = \React\EventLoop\Factory::create();
        $connection = $this->createConnection($loop);

        $connection->query('select null')->then(function (QueryResult $command) {
            $this->assertCount(1, $command->resultRows);
            $this->assertCount(1, $command->resultRows[0]);
            $this->assertNull(reset($command->resultRows[0]));

            $this->assertCount(1, $command->resultFields);
            $this->assertSame(Constants::FIELD_TYPE_NULL, $command->resultFields[0]['type']);
        });

        $connection->quit();
        $loop->run();
    }

    public function testSelectStaticTextTwoRows()
    {
        $loop = \React\EventLoop\Factory::create();
        $connection = $this->createConnection($loop);

        $connection->query('select "foo" UNION select "bar"')->then(function (QueryResult $command) {
            $this->assertCount(2, $command->resultRows);
            $this->assertCount(1, $command->resultRows[0]);

            $this->assertSame('foo', reset($command->resultRows[0]));
            $this->assertSame('bar', reset($command->resultRows[1]));
        });

        $connection->quit();
        $loop->run();
    }

    public function testSelectStaticTextTwoRowsWithNullHasTypeString()
    {
        $loop = \React\EventLoop\Factory::create();
        $connection = $this->createConnection($loop);

        $connection->query('select "foo" UNION select null')->then(function (QueryResult $command) {
            $this->assertCount(2, $command->resultRows);
            $this->assertCount(1, $command->resultRows[0]);

            $this->assertSame('foo', reset($command->resultRows[0]));
            $this->assertNull(reset($command->resultRows[1]));

            $this->assertCount(1, $command->resultFields);
            $this->assertSame(Constants::FIELD_TYPE_VAR_STRING, $command->resultFields[0]['type']);
        });

        $connection->quit();
        $loop->run();
    }

    public function testSelectStaticIntegerTwoRowsWithNullHasTypeLongButReturnsIntAsString()
    {
        $loop = \React\EventLoop\Factory::create();
        $connection = $this->createConnection($loop);

        $connection->query('select 0 UNION select null')->then(function (QueryResult $command) {
            $this->assertCount(2, $command->resultRows);
            $this->assertCount(1, $command->resultRows[0]);

            $this->assertSame('0', reset($command->resultRows[0]));
            $this->assertNull(reset($command->resultRows[1]));

            $this->assertCount(1, $command->resultFields);
            $this->assertSame(Constants::FIELD_TYPE_LONGLONG, $command->resultFields[0]['type']);
        });

        $connection->quit();
        $loop->run();
    }

    public function testSelectStaticTextTwoRowsWithIntegerHasTypeString()
    {
        $loop = \React\EventLoop\Factory::create();
        $connection = $this->createConnection($loop);

        $connection->query('select "foo" UNION select 1')->then(function (QueryResult $command) {
            $this->assertCount(2, $command->resultRows);
            $this->assertCount(1, $command->resultRows[0]);

            $this->assertSame('foo', reset($command->resultRows[0]));
            $this->assertSame('1', reset($command->resultRows[1]));

            $this->assertCount(1, $command->resultFields);
            $this->assertSame(Constants::FIELD_TYPE_VAR_STRING, $command->resultFields[0]['type']);
        });

        $connection->quit();
        $loop->run();
    }

    public function testSelectStaticTextTwoRowsWithEmptyRow()
    {
        $loop = \React\EventLoop\Factory::create();
        $connection = $this->createConnection($loop);

        $connection->query('select "foo" UNION select ""')->then(function (QueryResult $command) {
            $this->assertCount(2, $command->resultRows);
            $this->assertCount(1, $command->resultRows[0]);

            $this->assertSame('foo', reset($command->resultRows[0]));
            $this->assertSame('', reset($command->resultRows[1]));
        });

        $connection->quit();
        $loop->run();
    }

    public function testSelectStaticTextNoRows()
    {
        $loop = \React\EventLoop\Factory::create();
        $connection = $this->createConnection($loop);

        $connection->query('select "foo" LIMIT 0')->then(function (QueryResult $command) {
            $this->assertCount(0, $command->resultRows);

            $this->assertCount(1, $command->resultFields);
            $this->assertSame('foo', $command->resultFields[0]['name']);
        });

        $connection->quit();
        $loop->run();
    }

    public function testSelectStaticTextTwoColumns()
    {
        $loop = \React\EventLoop\Factory::create();
        $connection = $this->createConnection($loop);

        $connection->query('select "foo","bar"')->then(function (QueryResult $command) {
            $this->assertCount(1, $command->resultRows);
            $this->assertCount(2, $command->resultRows[0]);

            $this->assertSame('foo', reset($command->resultRows[0]));
            $this->assertSame('bar', next($command->resultRows[0]));
        });

        $connection->quit();
        $loop->run();
    }

    public function testSelectStaticTextTwoColumnsWithOneEmptyColumn()
    {
        $loop = \React\EventLoop\Factory::create();
        $connection = $this->createConnection($loop);

        $connection->query('select "foo",""')->then(function (QueryResult $command) {
            $this->assertCount(1, $command->resultRows);
            $this->assertCount(2, $command->resultRows[0]);

            $this->assertSame('foo', reset($command->resultRows[0]));
            $this->assertSame('', next($command->resultRows[0]));
        });

        $connection->quit();
        $loop->run();
    }

    public function testSelectStaticTextTwoColumnsWithBothEmpty()
    {
        $loop = \React\EventLoop\Factory::create();
        $connection = $this->createConnection($loop);

        $connection->query('select \'\' as `first`, \'\' as `second`')->then(function (QueryResult $command) {
            $this->assertCount(1, $command->resultRows);
            $this->assertCount(2, $command->resultRows[0]);
            $this->assertSame(array('', ''), array_values($command->resultRows[0]));

            $this->assertCount(2, $command->resultFields);
            $this->assertSame(Constants::FIELD_TYPE_VAR_STRING, $command->resultFields[0]['type']);
            $this->assertSame(Constants::FIELD_TYPE_VAR_STRING, $command->resultFields[1]['type']);
        });

        $connection->quit();
        $loop->run();
    }

    public function testSelectStaticTextTwoColumnsWithSameNameOverwritesValue()
    {
        $loop = \React\EventLoop\Factory::create();
        $connection = $this->createConnection($loop);

        $connection->query('select "foo" as `col`,"bar" as `col`')->then(function (QueryResult $command) {
            $this->assertCount(1, $command->resultRows);
            $this->assertCount(1, $command->resultRows[0]);

            $this->assertSame('bar', reset($command->resultRows[0]));

            $this->assertCount(2, $command->resultFields);
            $this->assertSame('col', $command->resultFields[0]['name']);
            $this->assertSame('col', $command->resultFields[1]['name']);
        });

        $connection->quit();
        $loop->run();
    }

    public function testSimpleSelect()
    {
        $loop = \React\EventLoop\Factory::create();
        $connection = $this->createConnection($loop);

        // re-create test "book" table
        $connection->query('DROP TABLE IF EXISTS book');
        $connection->query($this->getDataTable());
        $connection->query("insert into book (`name`) values ('foo')");
        $connection->query("insert into book (`name`) values ('bar')");

        $connection->query('select * from book')->then(function (QueryResult $command) {
            $this->assertCount(2, $command->resultRows);
        });

        $connection->quit();
        $loop->run();
    }

    /**
     * @depends testSimpleSelect
     */
    public function testSimpleSelectFromLazyConnectionWithoutDatabaseNameReturnsSameData()
    {
        $loop = \React\EventLoop\Factory::create();
        $factory = new Factory($loop);

        $uri = $this->getConnectionString(array('dbname' => ''));
        $connection = $factory->createLazyConnection($uri);

        $connection->query('select * from test.book')->then(function (QueryResult $command) {
            $this->assertCount(2, $command->resultRows);
        })->done();

        $connection->quit();
        $loop->run();
    }

    public function testInvalidSelectShouldFail()
    {
        $loop = \React\EventLoop\Factory::create();
        $connection = $this->createConnection($loop);

        $options = $this->getConnectionOptions();
        $db = $options['dbname'];

        $connection->query('select * from invalid_table')->then(
            $this->expectCallableNever(),
            function (\Exception $error) {
                $this->assertEquals("Table '$db.invalid_table' doesn't exist", $error->getMessage());
            }
        );

        $connection->quit();
        $loop->run();
    }

    public function testInvalidMultiStatementsShouldFailToPreventSqlInjections()
    {
        $loop = \React\EventLoop\Factory::create();
        $connection = $this->createConnection($loop);

        $connection->query('select 1;select 2;')->then(
            $this->expectCallableNever(),
            function (\Exception $error) {
                $this->assertContains("You have an error in your SQL syntax", $error->getMessage());
            }
        );

        $connection->quit();
        $loop->run();
    }

    public function testSelectAfterDelay()
    {
        $loop = \React\EventLoop\Factory::create();
        $connection = $this->createConnection($loop);

        $loop->addTimer(0.1, function () use ($connection) {
            $connection->query('select 1+1')->then(function (QueryResult $command) {
                $this->assertEquals([['1+1' => 2]], $command->resultRows);
            });
            $connection->quit();
        });

        $timeout = $loop->addTimer(1, function () use ($loop) {
            $loop->stop();
            $this->fail('Test timeout');
        });
        $connection->on('close', function () use ($loop, $timeout) {
            $loop->cancelTimer($timeout);
        });

        $loop->run();
    }

    public function testQueryStreamStaticEmptyEmitsSingleRow()
    {
        $loop = \React\EventLoop\Factory::create();
        $connection = $this->createConnection($loop);

        $stream = $connection->queryStream('SELECT 1');
        $stream->on('data', $this->expectCallableOnceWith(array('1' => '1')));
        $stream->on('end', $this->expectCallableOnce());
        $stream->on('close', $this->expectCallableOnce());

        $connection->quit();

        $loop->run();
    }

    public function testQueryStreamBoundVariableEmitsSingleRow()
    {
        $loop = \React\EventLoop\Factory::create();
        $connection = $this->createConnection($loop);

        $stream = $connection->queryStream('SELECT ? as value', array('test'));
        $stream->on('data', $this->expectCallableOnceWith(array('value' => 'test')));
        $stream->on('end', $this->expectCallableOnce());
        $stream->on('close', $this->expectCallableOnce());

        $connection->quit();

        $loop->run();
    }

    public function testQueryStreamZeroRowsEmitsEndWithoutData()
    {
        $loop = \React\EventLoop\Factory::create();
        $connection = $this->createConnection($loop);

        $stream = $connection->queryStream('SELECT 1 LIMIT 0');
        $stream->on('data', $this->expectCallableNever());
        $stream->on('end', $this->expectCallableOnce());
        $stream->on('close', $this->expectCallableOnce());

        $connection->quit();

        $loop->run();
    }

    public function testQueryStreamInvalidStatementEmitsError()
    {
        $loop = \React\EventLoop\Factory::create();
        $connection = $this->createConnection($loop);

        $stream = $connection->queryStream('SELECT');
        $stream->on('data', $this->expectCallableNever());
        $stream->on('end', $this->expectCallableNever());
        $stream->on('error', $this->expectCallableOnce());
        $stream->on('close', $this->expectCallableOnce());

        $connection->quit();

        $loop->run();
    }

    public function testQueryStreamDropStatementEmitsEndWithoutData()
    {
        $loop = \React\EventLoop\Factory::create();
        $connection = $this->createConnection($loop);

        $stream = $connection->queryStream('DROP TABLE IF exists helloworldtest1');
        $stream->on('data', $this->expectCallableNever());
        $stream->on('end', $this->expectCallableOnce());
        $stream->on('close', $this->expectCallableOnce());

        $connection->quit();

        $loop->run();
    }

    public function testQueryStreamExplicitCloseEmitsCloseEventWithoutData()
    {
        $loop = \React\EventLoop\Factory::create();
        $connection = $this->createConnection($loop);

        $stream = $connection->queryStream('SELECT 1');
        $stream->on('data', $this->expectCallableNever());
        $stream->on('end', $this->expectCallableNever());
        $stream->on('close', $this->expectCallableOnce());
        $stream->close();

        $connection->quit();

        $loop->run();
    }

    public function testQueryStreamFromLazyConnectionEmitsSingleRow()
    {
        $loop = \React\EventLoop\Factory::create();
        $factory = new Factory($loop);

        $uri = $this->getConnectionString();
        $connection = $factory->createLazyConnection($uri);

        $stream = $connection->queryStream('SELECT 1');

        $stream->on('data', $this->expectCallableOnceWith([1 => '1']));
        $stream->on('end', $this->expectCallableOnce());
        $stream->on('close', $this->expectCallableOnce());

        $connection->quit();

        $loop->run();
    }

    public function testQueryStreamFromLazyConnectionWillErrorWhenConnectionIsClosed()
    {
        $loop = \React\EventLoop\Factory::create();
        $factory = new Factory($loop);

        $uri = $this->getConnectionString();
        $connection = $factory->createLazyConnection($uri);

        $stream = $connection->queryStream('SELECT 1');

        $stream->on('data', $this->expectCallableNever());
        $stream->on('error', $this->expectCallableOnce());
        $stream->on('close', $this->expectCallableOnce());

        $connection->close();

        $loop->run();
    }
}
