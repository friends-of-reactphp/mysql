<?php

namespace React\Tests\MySQL\Io;

use React\MySQL\Commands\QueryCommand;
use React\MySQL\Io\Executor;
use React\MySQL\Io\Parser;
use React\Stream\ThroughStream;
use React\Tests\MySQL\BaseTestCase;
use React\MySQL\Exception;

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

        // hack to inject command as current command
        $ref = new \ReflectionProperty($parser, 'currCommand');
        $ref->setAccessible(true);
        $ref->setValue($parser, $command);

        $stream->close();
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

    public function testSendingErrorFrameDuringHandshakeShouldEmitErrorOnFollowingCommand()
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

    public function testSendingIncompleteErrorFrameDuringHandshakeShouldNotEmitError()
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
