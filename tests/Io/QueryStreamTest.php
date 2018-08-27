<?php

use React\MySQL\Commands\QueryCommand;
use React\MySQL\Io\QueryStream;
use React\Tests\MySQL\BaseTestCase;

class QueryStreamTest extends BaseTestCase
{
    public function testDataEventWillBeForwardedFromCommandResult()
    {
        $command = new QueryCommand();
        $connection = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();

        $stream = new QueryStream($command, $connection);
        $stream->on('data', $this->expectCallableOnceWith(array('key' => 'value')));

        $command->emit('result', array(array('key' => 'value')));
    }

    public function testDataEventWillNotBeForwardedFromCommandResultAfterClosingStream()
    {
        $command = new QueryCommand();
        $connection = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();

        $stream = new QueryStream($command, $connection);
        $stream->on('data', $this->expectCallableNever());
        $stream->close();

        $command->emit('result', array(array('key' => 'value')));
    }

    public function testEndEventWillBeForwardedFromCommandResult()
    {
        $command = new QueryCommand();
        $connection = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();

        $stream = new QueryStream($command, $connection);
        $stream->on('end', $this->expectCallableOnce());
        $stream->on('close', $this->expectCallableOnce());

        $command->emit('end');
    }

    public function testSuccessEventWillBeForwardedFromCommandResultAsEndWithoutData()
    {
        $command = new QueryCommand();
        $connection = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();

        $stream = new QueryStream($command, $connection);
        $stream->on('data', $this->expectCallableNever());
        $stream->on('end', $this->expectCallableOnce());
        $stream->on('close', $this->expectCallableOnce());

        $command->emit('success');
    }

    public function testErrorEventWillBeForwardedFromCommandResult()
    {
        $command = new QueryCommand();
        $connection = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();

        $stream = new QueryStream($command, $connection);
        $stream->on('error', $this->expectCallableOnceWith($this->isInstanceOf('RuntimeException')));
        $stream->on('close', $this->expectCallableOnce());

        $command->emit('error', array(new RuntimeException()));
    }

    public function testPauseForwardsToConnectionAfterResultStarted()
    {
        $command = new QueryCommand();
        $connection = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $connection->expects($this->once())->method('pause');

        $stream = new QueryStream($command, $connection);
        $command->emit('result', array(array()));

        $stream->pause();
    }

    public function testPauseForwardsToConnectionWhenResultStarted()
    {
        $command = new QueryCommand();
        $connection = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $connection->expects($this->once())->method('pause');

        $stream = new QueryStream($command, $connection);
        $stream->pause();

        $command->emit('result', array(array()));
    }

    public function testPauseDoesNotForwardToConnectionWhenResultIsNotStarted()
    {
        $command = new QueryCommand();
        $connection = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $connection->expects($this->never())->method('pause');

        $stream = new QueryStream($command, $connection);
        $stream->pause();
    }

    public function testPauseDoesNotForwardToConnectionAfterClosing()
    {
        $command = new QueryCommand();
        $connection = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $connection->expects($this->never())->method('pause');

        $stream = new QueryStream($command, $connection);
        $stream->close();
        $stream->pause();
    }

    public function testResumeForwardsToConnectionAfterResultStarted()
    {
        $command = new QueryCommand();
        $connection = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $connection->expects($this->once())->method('resume');

        $stream = new QueryStream($command, $connection);
        $command->emit('result', array(array()));

        $stream->resume();
    }

    public function testResumeDoesNotForwardToConnectionAfterClosing()
    {
        $command = new QueryCommand();
        $connection = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $connection->expects($this->never())->method('resume');

        $stream = new QueryStream($command, $connection);
        $stream->close();
        $stream->resume();
    }

    public function testPipeReturnsDestStream()
    {
        $command = new QueryCommand();
        $connection = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();

        $stream = new QueryStream($command, $connection);

        $dest = $this->getMockBuilder('React\Stream\WritableStreamInterface')->getMock();
        $ret = $stream->pipe($dest);

        $this->assertSame($dest, $ret);
    }

    public function testCloseTwiceEmitsCloseEventOnce()
    {
        $command = new QueryCommand();
        $connection = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();

        $stream = new QueryStream($command, $connection);
        $stream->on('close', $this->expectCallableOnce());

        $stream->close();
        $stream->close();
    }

    public function testCloseForwardsResumeToConnectionIfPreviouslyPaused()
    {
        $command = new QueryCommand();
        $connection = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $connection->expects($this->once())->method('resume');

        $stream = new QueryStream($command, $connection);
        $command->emit('result', array(array()));
        $stream->pause();
        $stream->close();
    }

    public function testCloseDoesNotResumeConnectionIfNotPreviouslyPaused()
    {
        $command = new QueryCommand();
        $connection = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $connection->expects($this->never())->method('resume');

        $stream = new QueryStream($command, $connection);
        $stream->close();
    }

    public function testCloseDoesNotResumeConnectionIfPreviouslyPausedWhenResultIsNotActive()
    {
        $command = new QueryCommand();
        $connection = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $connection->expects($this->never())->method('resume');

        $stream = new QueryStream($command, $connection);
        $stream->pause();
        $stream->close();
    }
}
