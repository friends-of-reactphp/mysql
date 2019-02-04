<?php

namespace React\Tests\MySQL\Io;

use PHPUnit\Framework\TestCase;
use React\MySQL\Io\Buffer;

class BufferTest extends TestCase
{
    public function testAppendAndReadBinary()
    {
        $buffer = new Buffer();

        $buffer->append('hello');

        $this->assertSame('hello', $buffer->read(5));
    }

    /**
     * @expectedException LogicException
     */
    public function testReadBeyondLimitThrows()
    {
        $buffer = new Buffer();

        $buffer->append('hi');

        $buffer->read(3);
    }

    public function testReadAfterSkipOne()
    {
        $buffer = new Buffer();

        $buffer->append('hi');
        $buffer->skip(1);

        $this->assertSame('i', $buffer->read(1));
    }

    /**
     * @expectedException LogicException
     */
    public function testSkipZeroThrows()
    {
        $buffer = new Buffer();

        $buffer->append('hi');

        $buffer->skip(0);
    }

    /**
     * @expectedException LogicException
     */
    public function testSkipBeyondLimitThrows()
    {
        $buffer = new Buffer();

        $buffer->append('hi');

        $buffer->skip(3);
    }

    public function testTrimEmptyIsNoop()
    {
        $buffer = new Buffer();
        $buffer->trim();

        $this->assertSame(0, $buffer->length());
    }

    public function testTrimDoesNotChangeLength()
    {
        $buffer = new Buffer();
        $buffer->append('a');
        $buffer->trim();

        $this->assertSame(1, $buffer->length());
    }

    public function testParseInt1()
    {
        $buffer = new Buffer();

        $buffer->append($buffer->buildInt1(0) . $buffer->buildInt1(255));

        $this->assertSame(0, $buffer->readInt1());
        $this->assertSame(255, $buffer->readInt1());
    }

    public function testParseInt2()
    {
        $buffer = new Buffer();

        $buffer->append($buffer->buildInt2(0) . $buffer->buildInt2(65535));

        $this->assertSame(0, $buffer->readInt2());
        $this->assertSame(65535, $buffer->readInt2());
    }

    public function testParseInt3()
    {
        $buffer = new Buffer();

        $buffer->append($buffer->buildInt3(0) . $buffer->buildInt3(0xFFFFFF));

        $this->assertSame(0, $buffer->readInt3());
        $this->assertSame(0xFFFFFF, $buffer->readInt3());
    }

    public function testParseInt8()
    {
        $buffer = new Buffer();

        $buffer->append($buffer->buildInt8(0) . $buffer->buildInt8(PHP_INT_MAX));

        $this->assertSame(0, $buffer->readInt8());
        $this->assertSame(PHP_INT_MAX, $buffer->readInt8());
    }

    public function testParseIntLen()
    {
        $buffer = new Buffer();

        $buffer->append("\x0A" . "\xFC" . "\x00\x04");

        $this->assertSame(10, $buffer->readIntLen());
        $this->assertSame(1024, $buffer->readIntLen());
    }

    public function testParseStringEmpty()
    {
        $buffer = new Buffer();

        $data = $buffer->buildStringLen('');
        $this->assertEquals("\x00", $data);

        $buffer->append($data);
        $this->assertSame('', $buffer->readStringLen());
    }

    public function testParseStringShort()
    {
        $buffer = new Buffer();

        $data = $buffer->buildStringLen('hello');
        $this->assertEquals("\x05" . "hello", $data);

        $buffer->append($data);
        $this->assertSame('hello', $buffer->readStringLen());
    }

    public function testParseStringKilo()
    {
        $buffer = new Buffer();

        $buffer->append($buffer->buildStringLen(str_repeat('.', 1024)));

        $this->assertSame(1024, strlen($buffer->readStringLen()));
    }

    public function testParseStringMega()
    {
        $buffer = new Buffer();

        $buffer->append($buffer->buildStringLen(str_repeat('.', 1000000)));

        $this->assertSame(1000000, strlen($buffer->readStringLen()));
    }

    /**
     * Test encoding/parsing string larger than 16 MiB. This should not happen
     * in practice as the protocol parser is currently limited to a packet
     * size of 16 MiB.
     */
    public function testParseStringExcessive()
    {
        $buffer = new Buffer();

        $buffer->append($buffer->buildStringLen(str_repeat('.', 17000000)));

        $this->assertSame(17000000, strlen($buffer->readStringLen()));
    }

    public function testParseStringNullLength()
    {
        $buffer = new Buffer();

        $data = $buffer->buildStringLen(null);
        $this->assertEquals("\xFB", $data);

        $buffer->append($data);
        $this->assertNull($buffer->readStringLen());
    }

    public function testParseStringNullCharacterTwice()
    {
        $buffer = new Buffer();
        $buffer->append("hello" . "\x00" . "world" . "\x00");

        $this->assertEquals('hello', $buffer->readStringNull());
        $this->assertEquals('world', $buffer->readStringNull());
    }

    /**
     * @expectedException LogicException
     */
    public function testParseStringNullCharacterThrowsIfNullNotFound()
    {
        $buffer = new Buffer();
        $buffer->append("hello");

        $buffer->readStringNull();
    }
}
