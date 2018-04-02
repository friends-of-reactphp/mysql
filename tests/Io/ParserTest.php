<?php

namespace React\Tests\MySQL\Io;

use React\MySQL\Commands\QueryCommand;
use React\MySQL\Io\Executor;
use React\MySQL\Io\Parser;
use React\Stream\ThroughStream;
use React\Tests\MySQL\BaseTestCase;

class ParserTest extends BaseTestCase
{
    public function testClosingStreamEmitsCloseEvent()
    {
        $stream = new ThroughStream();
        $connection = $this->getMockBuilder('React\MySQL\ConnectionInterface')->disableOriginalConstructor()->getMock();
        $executor = new Executor($connection);

        $parser = new Parser($stream, $executor);
        $parser->start();

        $parser->on('close', $this->expectCallableOnce());

        $stream->close();
    }

    public function testClosingStreamEmitsErrorForCurrentCommand()
    {
        $stream = new ThroughStream();
        $connection = $this->getMockBuilder('React\MySQL\ConnectionInterface')->disableOriginalConstructor()->getMock();
        $executor = new Executor($connection);

        $parser = new Parser($stream, $executor);
        $parser->start();

        $command = new QueryCommand($connection);
        $command->on('error', $this->expectCallableOnce());

        // hack to inject command as current command
        $parser->setOptions(array('currCommand' => $command));

        $stream->close();
    }

    public function testAppendAndReadBinary()
    {
        $stream = new ThroughStream();
        $connection = $this->getMockBuilder('React\MySQL\ConnectionInterface')->disableOriginalConstructor()->getMock();
        $executor = new Executor($connection);

        $parser = new Parser($stream, $executor);

        $parser->append('hello');

        $this->assertSame('hello', $parser->read(5));
    }

    /**
     * @expectedException LogicException
     */
    public function testReadBinaryBeyondLimitThrows()
    {
        $stream = new ThroughStream();
        $connection = $this->getMockBuilder('React\MySQL\ConnectionInterface')->disableOriginalConstructor()->getMock();
        $executor = new Executor($connection);

        $parser = new Parser($stream, $executor);

        $parser->append('hi');

        $parser->read(3);
    }

    public function testParseInt1()
    {
        $stream = new ThroughStream();
        $connection = $this->getMockBuilder('React\MySQL\ConnectionInterface')->disableOriginalConstructor()->getMock();
        $executor = new Executor($connection);

        $parser = new Parser($stream, $executor);

        $parser->append($parser->buildInt1(0) . $parser->buildInt1(255));

        $this->assertSame(0, $parser->readInt1());
        $this->assertSame(255, $parser->readInt1());
    }

    public function testParseInt2()
    {
        $stream = new ThroughStream();
        $connection = $this->getMockBuilder('React\MySQL\ConnectionInterface')->disableOriginalConstructor()->getMock();
        $executor = new Executor($connection);

        $parser = new Parser($stream, $executor);

        $parser->append($parser->buildInt2(0) . $parser->buildInt2(65535));

        $this->assertSame(0, $parser->readInt2());
        $this->assertSame(65535, $parser->readInt2());
    }

    public function testParseInt3()
    {
        $stream = new ThroughStream();
        $connection = $this->getMockBuilder('React\MySQL\ConnectionInterface')->disableOriginalConstructor()->getMock();
        $executor = new Executor($connection);

        $parser = new Parser($stream, $executor);

        $parser->append($parser->buildInt3(0) . $parser->buildInt3(0xFFFFFF));

        $this->assertSame(0, $parser->readInt3());
        $this->assertSame(0xFFFFFF, $parser->readInt3());
    }

    public function testParseInt8()
    {
        $stream = new ThroughStream();
        $connection = $this->getMockBuilder('React\MySQL\ConnectionInterface')->disableOriginalConstructor()->getMock();
        $executor = new Executor($connection);

        $parser = new Parser($stream, $executor);

        $parser->append($parser->buildInt8(0) . $parser->buildInt8(PHP_INT_MAX));

        $this->assertSame(0, $parser->readInt8());
        $this->assertSame(PHP_INT_MAX, $parser->readInt8());
    }

    public function testParseIntLen()
    {
        $stream = new ThroughStream();
        $connection = $this->getMockBuilder('React\MySQL\ConnectionInterface')->disableOriginalConstructor()->getMock();
        $executor = new Executor($connection);

        $parser = new Parser($stream, $executor);

        $parser->append("\x0A" . "\xFC" . "\x00\x04");

        $this->assertSame(10, $parser->readIntLen());
        $this->assertSame(1024, $parser->readIntLen());
    }

    public function testParseStringEmpty()
    {
        $stream = new ThroughStream();
        $connection = $this->getMockBuilder('React\MySQL\ConnectionInterface')->disableOriginalConstructor()->getMock();
        $executor = new Executor($connection);

        $parser = new Parser($stream, $executor);

        $data = $parser->buildStringLen('');
        $this->assertEquals("\x00", $data);

        $parser->append($data);
        $this->assertSame('', $parser->readStringLen());
    }

    public function testParseStringShort()
    {
        $stream = new ThroughStream();
        $connection = $this->getMockBuilder('React\MySQL\ConnectionInterface')->disableOriginalConstructor()->getMock();
        $executor = new Executor($connection);

        $parser = new Parser($stream, $executor);

        $data = $parser->buildStringLen('hello');
        $this->assertEquals("\x05" . "hello", $data);

        $parser->append($data);
        $this->assertSame('hello', $parser->readStringLen());
    }

    public function testParseStringKilo()
    {
        $stream = new ThroughStream();
        $connection = $this->getMockBuilder('React\MySQL\ConnectionInterface')->disableOriginalConstructor()->getMock();
        $executor = new Executor($connection);

        $parser = new Parser($stream, $executor);

        $parser->append($parser->buildStringLen(str_repeat('.', 1024)));

        $this->assertSame(1024, strlen($parser->readStringLen()));
    }

    public function testParseStringMega()
    {
        $stream = new ThroughStream();
        $connection = $this->getMockBuilder('React\MySQL\ConnectionInterface')->disableOriginalConstructor()->getMock();
        $executor = new Executor($connection);

        $parser = new Parser($stream, $executor);

        $parser->append($parser->buildStringLen(str_repeat('.', 1000000)));

        $this->assertSame(1000000, strlen($parser->readStringLen()));
    }

    /**
     * Test encoding/parsing string larger than 16 MiB. This should not happen
     * in practice as the protocol parser is currently limited to a packet
     * size of 16 MiB.
     */
    public function testParseStringExcessive()
    {
        $stream = new ThroughStream();
        $connection = $this->getMockBuilder('React\MySQL\ConnectionInterface')->disableOriginalConstructor()->getMock();
        $executor = new Executor($connection);

        $parser = new Parser($stream, $executor);

        $parser->append($parser->buildStringLen(str_repeat('.', 17000000)));

        $this->assertSame(17000000, strlen($parser->readStringLen()));
    }

    public function testParseStringNull()
    {
        $stream = new ThroughStream();
        $connection = $this->getMockBuilder('React\MySQL\ConnectionInterface')->disableOriginalConstructor()->getMock();
        $executor = new Executor($connection);

        $parser = new Parser($stream, $executor);

        $data = $parser->buildStringLen(null);
        $this->assertEquals("\xFB", $data);

        $parser->append($data);
        $this->assertNull($parser->readStringLen());
    }
}
