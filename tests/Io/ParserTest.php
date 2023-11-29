<?php

namespace React\Tests\Mysql\Io;

use React\Mysql\Commands\QueryCommand;
use React\Mysql\Exception;
use React\Mysql\Io\Executor;
use React\Mysql\Io\Parser;
use React\Stream\CompositeStream;
use React\Stream\ThroughStream;
use React\Tests\Mysql\BaseTestCase;

class ParserTest extends BaseTestCase
{
    public function testClosingStreamEmitsErrorForCurrentCommand()
    {
        $stream = new ThroughStream();
        $executor = new Executor();

        $parser = new Parser($stream, $executor);
        $parser->start();

        $command = new QueryCommand();
        $command->on('error', $this->expectCallableOnce());

        $error = null;
        $command->on('error', function ($e) use (&$error) {
            $error = $e;
        });

        // hack to inject command as current command
        $ref = new \ReflectionProperty($parser, 'currCommand');
        $ref->setAccessible(true);
        $ref->setValue($parser, $command);

        $stream->close();

        $this->assertInstanceOf('RuntimeException', $error);
        assert($error instanceof \RuntimeException);

        $this->assertEquals('Connection closing (ECONNABORTED)', $error->getMessage());
        $this->assertEquals(defined('SOCKET_ECONNABORTED') ? SOCKET_ECONNABORTED : 103, $error->getCode());
    }

    public function testUnexpectedErrorWithoutCurrentCommandWillBeIgnored()
    {
        $stream = new ThroughStream();

        $executor = new Executor();

        $parser = new Parser($stream, $executor);
        $parser->start();

        $stream->on('close', $this->expectCallableNever());

        $stream->write("\x33\0\0\0" . "\x0a" . "mysql\0" . str_repeat("\0", 44));
        $stream->write("\x17\0\0\0" . "\xFF" . "\x10\x04" . "Too many connections");
    }

    public function testReceivingErrorFrameDuringHandshakeShouldEmitErrorOnFollowingCommand()
    {
        $stream = new ThroughStream();

        $command = new QueryCommand();
        $command->on('error', $this->expectCallableOnce());

        $error = null;
        $command->on('error', function ($e) use (&$error) {
            $error = $e;
        });

        $executor = new Executor();
        $executor->enqueue($command);

        $parser = new Parser($stream, $executor);
        $parser->start();

        $stream->write("\x17\0\0\0" . "\xFF" . "\x10\x04" . "Too many connections");

        $this->assertTrue($error instanceof Exception);
        $this->assertEquals(1040, $error->getCode());
        $this->assertEquals('Too many connections', $error->getMessage());
    }

    public function testReceivingErrorFrameForQueryShouldEmitError()
    {
        $stream = new ThroughStream();

        $command = new QueryCommand();
        $command->on('error', $this->expectCallableOnce());

        $error = null;
        $command->on('error', function ($e) use (&$error) {
            $error = $e;
        });

        $executor = new Executor();
        $executor->enqueue($command);

        $parser = new Parser($stream, $executor);
        $parser->start();

        $stream->on('close', $this->expectCallableNever());

        $stream->write("\x33\0\0\0" . "\x0a" . "mysql\0" . str_repeat("\0", 44));
        $stream->write("\x1E\0\0\1" . "\xFF" . "\x46\x04" . "#abcde" . "Unknown thread id: 42");

        $this->assertTrue($error instanceof Exception);
        $this->assertEquals(1094, $error->getCode());
        $this->assertEquals('Unknown thread id: 42', $error->getMessage());
    }

    public function testReceivingErrorFrameForQueryAfterResultSetHeadersShouldEmitError()
    {
        $stream = new ThroughStream();

        $command = new QueryCommand();
        $command->on('error', $this->expectCallableOnce());

        $error = null;
        $command->on('error', function ($e) use (&$error) {
            $error = $e;
        });

        $executor = new Executor();
        $executor->enqueue($command);

        $parser = new Parser(new CompositeStream($stream, new ThroughStream()), $executor);
        $parser->start();

        $stream->on('close', $this->expectCallableNever());

        $stream->write("\x33\0\0\0" . "\x0a" . "mysql\0" . str_repeat("\0", 44));
        $stream->write("\x01\0\0\1" . "\x01");
        $stream->write("\x1F\0\0\2" . "\x03" . "def" . "\0" . "\0" . "\0" . "\x09" . "sleep(10)" . "\0" . "\xC0" . "\x3F\0" . "\1\0\0\0" . "\3" . "\x81\0". "\0" . "\0\0");
        $stream->write("\x05\0\0\3" . "\xFE" . "\0\0\2\0");
        $stream->write("\x28\0\0\4" . "\xFF" . "\x25\x05" . "#abcde" . "Query execution was interrupted");

        $this->assertTrue($error instanceof Exception);
        $this->assertEquals(1317, $error->getCode());
        $this->assertEquals('Query execution was interrupted', $error->getMessage());

        $ref = new \ReflectionProperty($parser, 'rsState');
        $ref->setAccessible(true);
        $this->assertEquals(0, $ref->getValue($parser));

        $ref = new \ReflectionProperty($parser, 'resultFields');
        $ref->setAccessible(true);
        $this->assertEquals([], $ref->getValue($parser));
    }

    public function testReceivingInvalidPacketWithMissingDataShouldEmitErrorAndCloseConnection()
    {
        $stream = new ThroughStream();

        $command = new QueryCommand();
        $command->on('error', $this->expectCallableOnce());

        $error = null;
        $command->on('error', function ($e) use (&$error) {
            $error = $e;
        });

        $executor = new Executor();
        $executor->enqueue($command);

        $parser = new Parser(new CompositeStream($stream, new ThroughStream()), $executor);
        $parser->start();

        // hack to inject command as current command
        $ref = new \ReflectionProperty($parser, 'currCommand');
        $ref->setAccessible(true);
        $ref->setValue($parser, $command);

        $stream->on('close', $this->expectCallableOnce());

        $stream->write("\x32\0\0\0" . "\x0a" . "mysql\0" . str_repeat("\0", 43));

        $this->assertTrue($error instanceof \UnexpectedValueException);
        $this->assertEquals('Unexpected protocol error, received malformed packet: Not enough data in buffer', $error->getMessage());
        $this->assertEquals(0, $error->getCode());
        $this->assertInstanceOf('UnderflowException', $error->getPrevious());
    }

    public function testReceivingInvalidPacketWithExcessiveDataShouldEmitErrorAndCloseConnection()
    {
        $stream = new ThroughStream();

        $command = new QueryCommand();
        $command->on('error', $this->expectCallableOnce());

        $error = null;
        $command->on('error', function ($e) use (&$error) {
            $error = $e;
        });

        $executor = new Executor();
        $executor->enqueue($command);

        $parser = new Parser(new CompositeStream($stream, new ThroughStream()), $executor);
        $parser->start();

        // hack to inject command as current command
        $ref = new \ReflectionProperty($parser, 'currCommand');
        $ref->setAccessible(true);
        $ref->setValue($parser, $command);

        $stream->on('close', $this->expectCallableOnce());

        $stream->write("\x34\0\0\0" . "\x0a" . "mysql\0" . str_repeat("\0", 45));

        $this->assertTrue($error instanceof \UnexpectedValueException);
        $this->assertEquals('Unexpected protocol error, received malformed packet with 1 unknown byte(s)', $error->getMessage());
        $this->assertEquals(0, $error->getCode());
        $this->assertNull($error->getPrevious());
    }

    public function testReceivingIncompleteErrorFrameDuringHandshakeShouldNotEmitError()
    {
        $stream = new ThroughStream();

        $command = new QueryCommand();
        $command->on('error', $this->expectCallableNever());

        $executor = new Executor();
        $executor->enqueue($command);

        $parser = new Parser($stream, $executor);
        $parser->start();

        $stream->write("\xFF\0\0\0" . "\xFF" . "\x12\x34" . "Some incomplete error message...");
    }
}
