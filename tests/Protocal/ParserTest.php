<?php

namespace React\Tests\MySQL\Protocal;

use React\MySQL\Commands\QueryCommand;
use React\MySQL\Executor;
use React\MySQL\Protocal\Parser;
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
}
