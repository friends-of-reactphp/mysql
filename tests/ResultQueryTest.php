<?php

namespace React\Tests\MySQL;

use React\EventLoop\Loop;
use React\MySQL\Io\Constants;
use React\MySQL\QueryResult;
use React\MySQL\Factory;

class ResultQueryTest extends BaseTestCase
{
    public function testSelectStaticText()
    {
        $connection = $this->createConnection(Loop::get());

        $connection->query('select \'foo\'')->then(function (QueryResult $command) {
            $this->assertCount(1, $command->resultRows);
            $this->assertCount(1, $command->resultRows[0]);
            $this->assertSame('foo', reset($command->resultRows[0]));

            $this->assertInstanceOf('React\MySQL\Connection', $conn);
        });

        $connection->quit();
        Loop::run();
    }

    public function provideValuesThatWillBeReturnedAsIs()
    {
        return array_map(function ($e) { return [$e]; }, [
            'foo',
            'hello?',
            'FööBär',
            'pile of 💩',
            'Dave\'s Diner',
            'Robert "Bobby"',
            "first\r\nsecond",
            'C:\\\\Users\\',
            '<>&--\'";',
            "\0\1\2\3\4\5\6\7\10\xff",
            implode('', range("\x00", "\x2F")) . implode('', range("\x7f", "\xFF")),
            '',
            null
        ]);
    }

    /**
     * @dataProvider provideValuesThatWillBeReturnedAsIs
     */
    public function testSelectStaticValueWillBeReturnedAsIs($value)
    {
        $connection = $this->createConnection(Loop::get());

        $expected = $value;

        $connection->query('select ?', [$value])->then(function (QueryResult $command) use ($expected) {
            $this->assertCount(1, $command->resultRows);
            $this->assertCount(1, $command->resultRows[0]);
            $this->assertSame($expected, reset($command->resultRows[0]));
        })->then(null, 'printf');

        $connection->quit();
        Loop::run();
    }

    /**
     * @dataProvider provideValuesThatWillBeReturnedAsIs
     */
    public function testSelectStaticValueWillBeReturnedAsIsWithNoBackslashEscapesSqlMode($value)
    {
        if ($value !== null && strpos($value, '\\') !== false) {
            // TODO: strings such as '%\\' work as-is when string contains percent?!
            $this->markTestIncomplete('Escaping backslash not supported when using NO_BACKSLASH_ESCAPES SQL mode');
        }

        $connection = $this->createConnection(Loop::get());

        $expected = $value;

        $connection->query('SET SQL_MODE="NO_BACKSLASH_ESCAPES"');
        $connection->query('select ?', [$value])->then(function (QueryResult $command) use ($expected) {
            $this->assertCount(1, $command->resultRows);
            $this->assertCount(1, $command->resultRows[0]);
            $this->assertSame($expected, reset($command->resultRows[0]));
        })->then(null, 'printf');

        $connection->quit();
        Loop::run();
    }

    public function provideValuesThatWillBeConvertedToString()
    {
        return [
            [1, '1'],
            [1.5, '1.5'],
            [true, '1'],
            [false, '0']
        ];
    }

    /**
     * @dataProvider provideValuesThatWillBeConvertedToString
     */
    public function testSelectStaticValueWillBeConvertedToString($value, $expected)
    {
        $connection = $this->createConnection(Loop::get());

        $connection->query('select ?', [$value])->then(function (QueryResult $command) use ($expected) {
            $this->assertCount(1, $command->resultRows);
            $this->assertCount(1, $command->resultRows[0]);
            $this->assertSame($expected, reset($command->resultRows[0]));
        });

        $connection->quit();
        Loop::run();
    }

    public function testSelectStaticTextWithQuestionMark()
    {
        $connection = $this->createConnection(Loop::get());

        $connection->query('select \'hello?\'')->then(function (QueryResult $command) {
            $this->assertCount(1, $command->resultRows);
            $this->assertCount(1, $command->resultRows[0]);
            $this->assertEquals('hello?', reset($command->resultRows[0]));
        });

        $connection->quit();
        Loop::run();
    }

    public function testSelectLongStaticTextHasTypeStringWithValidLength()
    {
        $connection = $this->createConnection(Loop::get());

        $length = 40000;
        $value = str_repeat('.', $length);

        $connection->query('SELECT ?', [$value])->then(function (QueryResult $command) use ($length) {
            $this->assertCount(1, $command->resultFields);
            $this->assertEquals($length * 3, $command->resultFields[0]['length']);
            $this->assertSame(Constants::FIELD_TYPE_VAR_STRING, $command->resultFields[0]['type']);
        });

        $connection->quit();
        Loop::run();
    }

    public function testSelectStaticTextWithEmptyLabel()
    {
        $connection = $this->createConnection(Loop::get());

        $connection->query('select \'foo\' as ``')->then(function (QueryResult $command) {
            $this->assertCount(1, $command->resultRows);
            $this->assertCount(1, $command->resultRows[0]);
            $this->assertSame('foo', reset($command->resultRows[0]));
            $this->assertSame('', key($command->resultRows[0]));

            $this->assertCount(1, $command->resultFields);
            $this->assertSame('', $command->resultFields[0]['name']);
        });

        $connection->quit();
        Loop::run();
    }

    public function testSelectStaticNullHasTypeNull()
    {
        $connection = $this->createConnection(Loop::get());

        $connection->query('select null')->then(function (QueryResult $command) {
            $this->assertCount(1, $command->resultRows);
            $this->assertCount(1, $command->resultRows[0]);
            $this->assertNull(reset($command->resultRows[0]));

            $this->assertCount(1, $command->resultFields);
            $this->assertSame(Constants::FIELD_TYPE_NULL, $command->resultFields[0]['type']);
        });

        $connection->quit();
        Loop::run();
    }

    public function testSelectStaticTextTwoRows()
    {
        $connection = $this->createConnection(Loop::get());

        $connection->query('select "foo" UNION select "bar"')->then(function (QueryResult $command) {
            $this->assertCount(2, $command->resultRows);
            $this->assertCount(1, $command->resultRows[0]);

            $this->assertSame('foo', reset($command->resultRows[0]));
            $this->assertSame('bar', reset($command->resultRows[1]));
        });

        $connection->quit();
        Loop::run();
    }

    public function testSelectStaticTextTwoRowsWithNullHasTypeString()
    {
        $connection = $this->createConnection(Loop::get());

        $connection->query('select "foo" UNION select null')->then(function (QueryResult $command) {
            $this->assertCount(2, $command->resultRows);
            $this->assertCount(1, $command->resultRows[0]);

            $this->assertSame('foo', reset($command->resultRows[0]));
            $this->assertNull(reset($command->resultRows[1]));

            $this->assertCount(1, $command->resultFields);
            $this->assertSame(Constants::FIELD_TYPE_VAR_STRING, $command->resultFields[0]['type']);
        });

        $connection->quit();
        Loop::run();
    }

    public function testSelectStaticIntegerTwoRowsWithNullHasTypeLongButReturnsIntAsString()
    {
        $connection = $this->createConnection(Loop::get());

        $connection->query('select 0 UNION select null')->then(function (QueryResult $command) {
            $this->assertCount(2, $command->resultRows);
            $this->assertCount(1, $command->resultRows[0]);

            $this->assertSame('0', reset($command->resultRows[0]));
            $this->assertNull(reset($command->resultRows[1]));

            $this->assertCount(1, $command->resultFields);
            $this->assertSame(Constants::FIELD_TYPE_LONGLONG, $command->resultFields[0]['type']);
        });

        $connection->quit();
        Loop::run();
    }

    public function testSelectStaticTextTwoRowsWithIntegerHasTypeString()
    {
        $connection = $this->createConnection(Loop::get());

        $connection->query('select "foo" UNION select 1')->then(function (QueryResult $command) {
            $this->assertCount(2, $command->resultRows);
            $this->assertCount(1, $command->resultRows[0]);

            $this->assertSame('foo', reset($command->resultRows[0]));
            $this->assertSame('1', reset($command->resultRows[1]));

            $this->assertCount(1, $command->resultFields);
            $this->assertSame(Constants::FIELD_TYPE_VAR_STRING, $command->resultFields[0]['type']);
        });

        $connection->quit();
        Loop::run();
    }

    public function testSelectStaticTextTwoRowsWithEmptyRow()
    {
        $connection = $this->createConnection(Loop::get());

        $connection->query('select "foo" UNION select ""')->then(function (QueryResult $command) {
            $this->assertCount(2, $command->resultRows);
            $this->assertCount(1, $command->resultRows[0]);

            $this->assertSame('foo', reset($command->resultRows[0]));
            $this->assertSame('', reset($command->resultRows[1]));
        });

        $connection->quit();
        Loop::run();
    }

    public function testSelectStaticTextNoRows()
    {
        $connection = $this->createConnection(Loop::get());

        $connection->query('select "foo" LIMIT 0')->then(function (QueryResult $command) {
            $this->assertCount(0, $command->resultRows);

            $this->assertCount(1, $command->resultFields);
            $this->assertSame('foo', $command->resultFields[0]['name']);
        });

        $connection->quit();
        Loop::run();
    }

    public function testSelectStaticTextTwoColumns()
    {
        $connection = $this->createConnection(Loop::get());

        $connection->query('select "foo","bar"')->then(function (QueryResult $command) {
            $this->assertCount(1, $command->resultRows);
            $this->assertCount(2, $command->resultRows[0]);

            $this->assertSame('foo', reset($command->resultRows[0]));
            $this->assertSame('bar', next($command->resultRows[0]));
        });

        $connection->quit();
        Loop::run();
    }

    public function testSelectStaticTextTwoColumnsWithOneEmptyColumn()
    {
        $connection = $this->createConnection(Loop::get());

        $connection->query('select "foo",""')->then(function (QueryResult $command) {
            $this->assertCount(1, $command->resultRows);
            $this->assertCount(2, $command->resultRows[0]);

            $this->assertSame('foo', reset($command->resultRows[0]));
            $this->assertSame('', next($command->resultRows[0]));
        });

        $connection->quit();
        Loop::run();
    }

    public function testSelectStaticTextTwoColumnsWithBothEmpty()
    {
        $connection = $this->createConnection(Loop::get());

        $connection->query('select \'\' as `first`, \'\' as `second`')->then(function (QueryResult $command) {
            $this->assertCount(1, $command->resultRows);
            $this->assertCount(2, $command->resultRows[0]);
            $this->assertSame(['', ''], array_values($command->resultRows[0]));

            $this->assertCount(2, $command->resultFields);
            $this->assertSame(Constants::FIELD_TYPE_VAR_STRING, $command->resultFields[0]['type']);
            $this->assertSame(Constants::FIELD_TYPE_VAR_STRING, $command->resultFields[1]['type']);
        });

        $connection->quit();
        Loop::run();
    }

    public function testSelectStaticTextTwoColumnsWithSameNameOverwritesValue()
    {
        $connection = $this->createConnection(Loop::get());

        $connection->query('select "foo" as `col`,"bar" as `col`')->then(function (QueryResult $command) {
            $this->assertCount(1, $command->resultRows);
            $this->assertCount(1, $command->resultRows[0]);

            $this->assertSame('bar', reset($command->resultRows[0]));

            $this->assertCount(2, $command->resultFields);
            $this->assertSame('col', $command->resultFields[0]['name']);
            $this->assertSame('col', $command->resultFields[1]['name']);
        });

        $connection->quit();
        Loop::run();
    }

    public function testSelectCharsetDefaultsToUtf8()
    {
        $connection = $this->createConnection(Loop::get());

        $connection->query('SELECT @@character_set_client')->then(function (QueryResult $command) {
            $this->assertCount(1, $command->resultRows);
            $this->assertCount(1, $command->resultRows[0]);
            $this->assertSame('utf8mb4', reset($command->resultRows[0]));
        });

        $connection->quit();
        Loop::run();
    }

    public function testSelectWithExplcitCharsetReturnsCharset()
    {
        $factory = new Factory();

        $uri = $this->getConnectionString() . '?charset=latin1';
        $connection = $factory->createLazyConnection($uri);

        $connection->query('SELECT @@character_set_client')->then(function (QueryResult $command) {
            $this->assertCount(1, $command->resultRows);
            $this->assertCount(1, $command->resultRows[0]);
            $this->assertSame('latin1', reset($command->resultRows[0]));
        });

        $connection->quit();
        Loop::run();
    }

    public function testSimpleSelect()
    {
        $connection = $this->createConnection(Loop::get());

        // re-create test "book" table
        $connection->query('DROP TABLE IF EXISTS book');
        $connection->query($this->getDataTable());
        $connection->query("insert into book (`name`) values ('foo')");
        $connection->query("insert into book (`name`) values ('bar')");

        $connection->query('select * from book')->then(function (QueryResult $command) {
            $this->assertCount(2, $command->resultRows);
        });

        $connection->quit();
        Loop::run();
    }

    /**
     * @depends testSimpleSelect
     */
    public function testSimpleSelectFromLazyConnectionWithoutDatabaseNameReturnsSameData()
    {
        $factory = new Factory();

        $uri = $this->getConnectionString(['dbname' => '']);
        $connection = $factory->createLazyConnection($uri);

        $connection->query('select * from test.book')->then(function (QueryResult $command) {
            $this->assertCount(2, $command->resultRows);
        });

        $connection->quit();
        Loop::run();
    }

    public function testInvalidSelectShouldFail()
    {
        $connection = $this->createConnection(Loop::get());

        $options = $this->getConnectionOptions();
        $db = $options['dbname'];

        $connection->query('select * from invalid_table')->then(
            $this->expectCallableNever(),
            function (\Exception $error) {
                $this->assertEquals("Table '$db.invalid_table' doesn't exist", $error->getMessage());
            }
        );

        $connection->quit();
        Loop::run();
    }

    public function testInvalidMultiStatementsShouldFailToPreventSqlInjections()
    {
        $connection = $this->createConnection(Loop::get());

        $connection->query('select 1;select 2;')->then(
            $this->expectCallableNever(),
            function (\Exception $error) {
                $this->assertContains("You have an error in your SQL syntax", $error->getMessage());
            }
        );

        $connection->quit();
        Loop::run();
    }

    public function testSelectAfterDelay()
    {
        $connection = $this->createConnection(Loop::get());

        Loop::addTimer(0.1, function () use ($connection) {
            $connection->query('select 1+1')->then(function (QueryResult $command) {
                $this->assertEquals([['1+1' => 2]], $command->resultRows);
            });
            $connection->quit();
        });

        $timeout = Loop::addTimer(1, function () {
            Loop::stop();
            $this->fail('Test timeout');
        });
        $connection->on('close', function () use ($timeout) {
            Loop::cancelTimer($timeout);
        });

        Loop::run();
    }

    public function testQueryStreamStaticEmptyEmitsSingleRow()
    {
        $connection = $this->createConnection(Loop::get());

        $stream = $connection->queryStream('SELECT 1');
        $stream->on('data', $this->expectCallableOnceWith(['1' => '1']));
        $stream->on('end', $this->expectCallableOnce());
        $stream->on('close', $this->expectCallableOnce());

        $connection->quit();
        Loop::run();
    }

    public function testQueryStreamBoundVariableEmitsSingleRow()
    {
        $connection = $this->createConnection(Loop::get());

        $stream = $connection->queryStream('SELECT ? as value', ['test']);
        $stream->on('data', $this->expectCallableOnceWith(['value' => 'test']));
        $stream->on('end', $this->expectCallableOnce());
        $stream->on('close', $this->expectCallableOnce());

        $connection->quit();
        Loop::run();
    }

    public function testQueryStreamZeroRowsEmitsEndWithoutData()
    {
        $connection = $this->createConnection(Loop::get());

        $stream = $connection->queryStream('SELECT 1 LIMIT 0');
        $stream->on('data', $this->expectCallableNever());
        $stream->on('end', $this->expectCallableOnce());
        $stream->on('close', $this->expectCallableOnce());

        $connection->quit();
        Loop::run();
    }

    public function testQueryStreamInvalidStatementEmitsError()
    {
        $connection = $this->createConnection(Loop::get());

        $stream = $connection->queryStream('SELECT');
        $stream->on('data', $this->expectCallableNever());
        $stream->on('end', $this->expectCallableNever());
        $stream->on('error', $this->expectCallableOnce());
        $stream->on('close', $this->expectCallableOnce());

        $connection->quit();
        Loop::run();
    }

    public function testQueryStreamDropStatementEmitsEndWithoutData()
    {
        $connection = $this->createConnection(Loop::get());

        $stream = $connection->queryStream('DROP TABLE IF exists helloworldtest1');
        $stream->on('data', $this->expectCallableNever());
        $stream->on('end', $this->expectCallableOnce());
        $stream->on('close', $this->expectCallableOnce());

        $connection->quit();
        Loop::run();
    }

    public function testQueryStreamExplicitCloseEmitsCloseEventWithoutData()
    {
        $connection = $this->createConnection(Loop::get());

        $stream = $connection->queryStream('SELECT 1');
        $stream->on('data', $this->expectCallableNever());
        $stream->on('end', $this->expectCallableNever());
        $stream->on('close', $this->expectCallableOnce());
        $stream->close();

        $connection->quit();
        Loop::run();
    }

    public function testQueryStreamFromLazyConnectionEmitsSingleRow()
    {
        $factory = new Factory();

        $uri = $this->getConnectionString();
        $connection = $factory->createLazyConnection($uri);

        $stream = $connection->queryStream('SELECT 1');

        $stream->on('data', $this->expectCallableOnceWith([1 => '1']));
        $stream->on('end', $this->expectCallableOnce());
        $stream->on('close', $this->expectCallableOnce());

        $connection->quit();
        Loop::run();
    }

    public function testQueryStreamFromLazyConnectionWillErrorWhenConnectionIsClosed()
    {
        $factory = new Factory();

        $uri = $this->getConnectionString();
        $connection = $factory->createLazyConnection($uri);

        $stream = $connection->queryStream('SELECT 1');

        $stream->on('data', $this->expectCallableNever());
        $stream->on('error', $this->expectCallableOnce());
        $stream->on('close', $this->expectCallableOnce());

        $connection->close();
    }

    protected function checkMaxAllowedPacket($connection, $min = 0x1100000): \React\Promise\PromiseInterface
    {
        return $connection->query('SHOW VARIABLES LIKE \'max_allowed_packet\'')->then(
            function ($res) use ($min, $connection) {
                $current = $res->resultRows[0]['Value'];
                if ($current < $min) {
                    return $connection->query('SET GLOBAL max_allowed_packet = ?', [$min])->otherwise(
                        function (\Throwable $e) use ($min, $current) {
                            throw new \Exception(
                                'Cannot set max_allowed_packet to: ' . $min . ' ' .
                                'current: ' . $current . ' error: ' . $e->getMessage()
                            );
                        }
                    );
                }
                return \React\Promise\resolve();
            }
        )->then(
            function () {
                return true;
            }
        );
    }

    /**
     * This should not trigger splitted packets sending
     */
    public function testSelectStaticTextSplitPacketsExactlyBelow16MiB()
    {
        $connection = $this->createConnection(Loop::get());

        $promise = $this->checkMaxAllowedPacket($connection, 0x1000000); // 16MiB

        $promise->then(
            function () use ($connection) {
                /**
                 * This should be exactly below 16MiB packet
                 *
                 * x03 + "select ''" = len(10)
                 */
                $text = str_repeat('A', 0xffffff - 11);
                $connection->query('select \'' . $text . '\'')->then(function (QueryResult $command) use ($text) {
                    $this->assertCount(1, $command->resultRows);
                    $this->assertCount(1, $command->resultRows[0]);
                    $this->assertSame($text, reset($command->resultRows[0]));
                })->done();
            }
        )->otherwise(
            function (\Throwable $e) {
                $this->markTestIncomplete('checkMaxAllowedPacket: ' . $e->getMessage());
            }
        )->always(
            function () use ($connection) {
                $connection->quit();
            }
        )->done();

        Loop::run();
    }

    /**
     * This should trigger splitted packets sending and
     * will send additional empty packet to signal to the server that splitted packets has ended.
     */
    public function testSelectStaticTextSplitPacketsExactly16MiB()
    {
        $connection = $this->createConnection(Loop::get());

        $promise = $this->checkMaxAllowedPacket($connection);

        $promise->then(
            function () use ($connection) {
                /**
                 * This should be exactly at 16MiB packet
                 *
                 * x03 + "select ''" = len(10)
                 */
                $text = str_repeat('A', 0xffffff - 10);
                $connection->query('select \'' . $text . '\'')->then(function (QueryResult $command) use ($text) {
                    $this->assertCount(1, $command->resultRows);
                    $this->assertCount(1, $command->resultRows[0]);
                    $this->assertSame($text, reset($command->resultRows[0]));
                })->done();
            }
        )->otherwise(
            function (\Throwable $e) {
                $this->markTestIncomplete('checkMaxAllowedPacket: ' . $e->getMessage());
            }
        )->always(
            function () use ($connection) {
                $connection->quit();
            }
        )->done();

        Loop::run();
    }

    public function testSelectStaticTextSplitPacketsAbove16MiB()
    {
        $connection = $this->createConnection(Loop::get());

        $promise = $this->checkMaxAllowedPacket($connection);

        $promise->then(
            function () use ($connection) {
                /**
                 * This should be exactly at 16MiB + 10 packet
                 *
                 * x03 + "select ''" = len(10)
                 */
                $text = str_repeat('A', 0xffffff);
                $connection->query('select \'' . $text . '\'')->then(function (QueryResult $command) use ($text) {
                    $this->assertCount(1, $command->resultRows);
                    $this->assertCount(1, $command->resultRows[0]);
                    $this->assertSame($text, reset($command->resultRows[0]));
                })->done();
            }
        )->otherwise(
            function (\Throwable $e) {
                $this->markTestIncomplete('checkMaxAllowedPacket: ' . $e->getMessage());
            }
        )->always(
            function () use ($connection) {
                $connection->quit();
            }
        )->done();

        Loop::run();
    }

    /**
     * Here we force the server to send us an empty packet when splitted packets are to be ended.
     */
    public function testSelectStaticTextSplitPacketsExactly16MiBResponse()
    {
        $connection = $this->createConnection(Loop::get());

        $promise = $this->checkMaxAllowedPacket($connection);

        $promise->then(
            function () use ($connection) {
                /**
                 * Server response will be exatctly 16MiB, so server will send another empty packet
                 * to signal end of splitted packets.
                 *
                 * x03 + "select ''" = len(10)
                 */
                $text = str_repeat('A', 0xffffff - 4);
                $connection->query('select \'' . $text . '\'')->then(function (QueryResult $command) use ($text) {
                    $this->assertCount(1, $command->resultRows);
                    $this->assertCount(1, $command->resultRows[0]);
                    $this->assertSame($text, reset($command->resultRows[0]));
                })->done();
            }
        )->otherwise(
            function (\Throwable $e) {
                $this->markTestIncomplete('checkMaxAllowedPacket: ' . $e->getMessage());
            }
        )->always(
            function () use ($connection) {
                $connection->quit();
            }
        )->done();

        Loop::run();
    }
}
