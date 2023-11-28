<?php

namespace React\Tests\Mysql\Io;

use React\Mysql\Io\Connection;
use React\Tests\Mysql\BaseTestCase;

class ConnectionTest extends BaseTestCase
{
    public function testIsBusyReturnsTrueWhenParserIsBusy()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();

        $executor = $this->getMockBuilder('React\Mysql\Io\Executor')->setMethods(['enqueue', 'isIdle'])->getMock();
        $executor->expects($this->once())->method('enqueue')->willReturnArgument(0);
        $executor->expects($this->never())->method('isIdle');

        $parser = $this->getMockBuilder('React\Mysql\Io\Parser')->disableOriginalConstructor()->getMock();
        $parser->expects($this->once())->method('isBusy')->willReturn(true);

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $connection = new Connection($stream, $executor, $parser, $loop, null);

        $connection->query('SELECT 1');

        $this->assertTrue($connection->isBusy());
    }

    public function testIsBusyReturnsFalseWhenParserIsNotBusyAndExecutorIsIdle()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();

        $executor = $this->getMockBuilder('React\Mysql\Io\Executor')->getMock();
        $executor->expects($this->once())->method('isIdle')->willReturn(true);

        $parser = $this->getMockBuilder('React\Mysql\Io\Parser')->disableOriginalConstructor()->getMock();

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $connection = new Connection($stream, $executor, $parser, $loop, null);

        $this->assertFalse($connection->isBusy());
    }

    public function testQueryWillEnqueueOneCommand()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $stream->expects($this->never())->method('close');

        $executor = $this->getMockBuilder('React\Mysql\Io\Executor')->setMethods(['enqueue'])->getMock();
        $executor->expects($this->once())->method('enqueue')->willReturnArgument(0);

        $parser = $this->getMockBuilder('React\Mysql\Io\Parser')->disableOriginalConstructor()->getMock();

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->never())->method('addTimer');

        $conn = new Connection($stream, $executor, $parser, $loop, null);
        $conn->query('SELECT 1');
    }

    public function testQueryWillReturnResolvedPromiseAndStartIdleTimerWhenQueryCommandEmitsSuccess()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();

        $currentCommand = null;
        $executor = $this->getMockBuilder('React\Mysql\Io\Executor')->setMethods(['enqueue'])->getMock();
        $executor->expects($this->once())->method('enqueue')->willReturnCallback(function ($command) use (&$currentCommand) {
            return $currentCommand = $command;
        });

        $parser = $this->getMockBuilder('React\Mysql\Io\Parser')->disableOriginalConstructor()->getMock();

        $timer = $this->getMockBuilder('React\EventLoop\TimerInterface')->getMock();
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addTimer')->with(0.001, $this->anything())->willReturn($timer);
        $loop->expects($this->never())->method('cancelTimer');

        $connection = new Connection($stream, $executor, $parser, $loop, null);

        $this->assertNull($currentCommand);

        $promise = $connection->query('SELECT 1');

        $promise->then($this->expectCallableOnceWith($this->isInstanceOf('React\Mysql\MysqlResult')));

        $this->assertNotNull($currentCommand);
        $currentCommand->emit('success');
    }

    public function testQueryWillReturnResolvedPromiseAndStartIdleTimerWhenQueryCommandEmitsEnd()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();

        $currentCommand = null;
        $executor = $this->getMockBuilder('React\Mysql\Io\Executor')->setMethods(['enqueue'])->getMock();
        $executor->expects($this->once())->method('enqueue')->willReturnCallback(function ($command) use (&$currentCommand) {
            return $currentCommand = $command;
        });

        $parser = $this->getMockBuilder('React\Mysql\Io\Parser')->disableOriginalConstructor()->getMock();

        $timer = $this->getMockBuilder('React\EventLoop\TimerInterface')->getMock();
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addTimer')->with(0.001, $this->anything())->willReturn($timer);
        $loop->expects($this->never())->method('cancelTimer');

        $connection = new Connection($stream, $executor, $parser, $loop, null);

        $this->assertNull($currentCommand);

        $promise = $connection->query('SELECT 1');

        $promise->then($this->expectCallableOnceWith($this->isInstanceOf('React\Mysql\MysqlResult')));

        $this->assertNotNull($currentCommand);
        $currentCommand->emit('end');
    }

    public function testQueryWillReturnResolvedPromiseAndStartIdleTimerWhenIdlePeriodIsGivenAndQueryCommandEmitsSuccess()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();

        $currentCommand = null;
        $executor = $this->getMockBuilder('React\Mysql\Io\Executor')->setMethods(['enqueue'])->getMock();
        $executor->expects($this->once())->method('enqueue')->willReturnCallback(function ($command) use (&$currentCommand) {
            return $currentCommand = $command;
        });

        $parser = $this->getMockBuilder('React\Mysql\Io\Parser')->disableOriginalConstructor()->getMock();

        $timer = $this->getMockBuilder('React\EventLoop\TimerInterface')->getMock();
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addTimer')->with(1.0, $this->anything())->willReturn($timer);
        $loop->expects($this->never())->method('cancelTimer');

        $connection = new Connection($stream, $executor, $parser, $loop, 1.0);

        $this->assertNull($currentCommand);

        $promise = $connection->query('SELECT 1');

        $promise->then($this->expectCallableOnceWith($this->isInstanceOf('React\Mysql\MysqlResult')));

        $this->assertNotNull($currentCommand);
        $currentCommand->emit('success');
    }

    public function testQueryWillReturnResolvedPromiseAndNotStartIdleTimerWhenIdlePeriodIsNegativeAndQueryCommandEmitsSuccess()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();

        $currentCommand = null;
        $executor = $this->getMockBuilder('React\Mysql\Io\Executor')->setMethods(['enqueue'])->getMock();
        $executor->expects($this->once())->method('enqueue')->willReturnCallback(function ($command) use (&$currentCommand) {
            return $currentCommand = $command;
        });

        $parser = $this->getMockBuilder('React\Mysql\Io\Parser')->disableOriginalConstructor()->getMock();

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->never())->method('addTimer');

        $connection = new Connection($stream, $executor, $parser, $loop, -1);

        $this->assertNull($currentCommand);

        $promise = $connection->query('SELECT 1');

        $promise->then($this->expectCallableOnceWith($this->isInstanceOf('React\Mysql\MysqlResult')));

        $this->assertNotNull($currentCommand);
        $currentCommand->emit('success');
    }

    public function testQueryWillReturnRejectedPromiseAndStartIdleTimerWhenQueryCommandEmitsError()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();

        $currentCommand = null;
        $executor = $this->getMockBuilder('React\Mysql\Io\Executor')->setMethods(['enqueue'])->getMock();
        $executor->expects($this->once())->method('enqueue')->willReturnCallback(function ($command) use (&$currentCommand) {
            return $currentCommand = $command;
        });

        $parser = $this->getMockBuilder('React\Mysql\Io\Parser')->disableOriginalConstructor()->getMock();

        $timer = $this->getMockBuilder('React\EventLoop\TimerInterface')->getMock();
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addTimer')->with(0.001, $this->anything())->willReturn($timer);
        $loop->expects($this->never())->method('cancelTimer');

        $connection = new Connection($stream, $executor, $parser, $loop, null);

        $this->assertNull($currentCommand);

        $promise = $connection->query('SELECT 1');

        $promise->then(null, $this->expectCallableOnce());

        $this->assertNotNull($currentCommand);
        $currentCommand->emit('error', [new \RuntimeException()]);
    }

    public function testQueryFollowedByIdleTimerWillQuitUnderlyingConnectionAndEmitCloseEventWhenQuitCommandEmitsSuccess()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $stream->expects($this->once())->method('close');

        $currentCommand = null;
        $executor = $this->getMockBuilder('React\Mysql\Io\Executor')->setMethods(['enqueue'])->getMock();
        $executor->expects($this->any())->method('enqueue')->willReturnCallback(function ($command) use (&$currentCommand) {
            return $currentCommand = $command;
        });

        $parser = $this->getMockBuilder('React\Mysql\Io\Parser')->disableOriginalConstructor()->getMock();

        $timeout = null;
        $timer = $this->getMockBuilder('React\EventLoop\TimerInterface')->getMock();
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addTimer')->with(0.001, $this->callback(function ($cb) use (&$timeout) {
            $timeout = $cb;
            return true;
        }))->willReturn($timer);

        $connection = new Connection($stream, $executor, $parser, $loop, null);

        $connection->on('close', $this->expectCallableOnce());

        $this->assertNull($currentCommand);

        $connection->query('SELECT 1');

        $this->assertNotNull($currentCommand);
        $currentCommand->emit('success');

        $this->assertNotNull($timeout);
        $timeout();

        $this->assertNotNull($currentCommand);
        $currentCommand->emit('success');
    }

    public function testQueryFollowedByIdleTimerWillQuitUnderlyingConnectionAndEmitCloseEventWhenQuitCommandEmitsError()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $stream->expects($this->once())->method('close');

        $currentCommand = null;
        $executor = $this->getMockBuilder('React\Mysql\Io\Executor')->setMethods(['enqueue'])->getMock();
        $executor->expects($this->any())->method('enqueue')->willReturnCallback(function ($command) use (&$currentCommand) {
            return $currentCommand = $command;
        });

        $parser = $this->getMockBuilder('React\Mysql\Io\Parser')->disableOriginalConstructor()->getMock();

        $timeout = null;
        $timer = $this->getMockBuilder('React\EventLoop\TimerInterface')->getMock();
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addTimer')->with(0.001, $this->callback(function ($cb) use (&$timeout) {
            $timeout = $cb;
            return true;
        }))->willReturn($timer);

        $connection = new Connection($stream, $executor, $parser, $loop, null);

        $connection->on('close', $this->expectCallableOnce());

        $this->assertNull($currentCommand);

        $connection->query('SELECT 1');

        $this->assertNotNull($currentCommand);
        $currentCommand->emit('success');

        $this->assertNotNull($timeout);
        $timeout();

        $this->assertNotNull($currentCommand);
        $currentCommand->emit('error', [new \RuntimeException()]);
    }

    public function testQueryTwiceWillEnqueueSecondQueryWithoutStartingIdleTimerWhenFirstQueryCommandEmitsSuccess()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();

        $currentCommand = null;
        $executor = $this->getMockBuilder('React\Mysql\Io\Executor')->setMethods(['enqueue'])->getMock();
        $executor->expects($this->any())->method('enqueue')->willReturnCallback(function ($command) use (&$currentCommand) {
            return $currentCommand = $command;
        });

        $parser = $this->getMockBuilder('React\Mysql\Io\Parser')->disableOriginalConstructor()->getMock();

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->never())->method('addTimer');

        $connection = new Connection($stream, $executor, $parser, $loop, null);

        $this->assertNull($currentCommand);

        $connection->query('SELECT 1');
        $connection->query('SELECT 2');

        $this->assertNotNull($currentCommand);
        $currentCommand->emit('success');
    }

    public function testQueryTwiceAfterIdleTimerWasStartedWillCancelIdleTimerAndEnqueueSecondCommand()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();

        $currentCommand = null;
        $executor = $this->getMockBuilder('React\Mysql\Io\Executor')->setMethods(['enqueue'])->getMock();
        $executor->expects($this->any())->method('enqueue')->willReturnCallback(function ($command) use (&$currentCommand) {
            return $currentCommand = $command;
        });

        $parser = $this->getMockBuilder('React\Mysql\Io\Parser')->disableOriginalConstructor()->getMock();

        $timer = $this->getMockBuilder('React\EventLoop\TimerInterface')->getMock();
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addTimer')->with(0.001, $this->anything())->willReturn($timer);
        $loop->expects($this->once())->method('cancelTimer')->with($timer);

        $connection = new Connection($stream, $executor, $parser, $loop, null);

        $this->assertNull($currentCommand);

        $connection->query('SELECT 1');

        $this->assertNotNull($currentCommand);
        $currentCommand->emit('success');

        $connection->query('SELECT 2');
    }

    public function testQueryStreamWillEnqueueOneCommand()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $stream->expects($this->never())->method('close');

        $executor = $this->getMockBuilder('React\Mysql\Io\Executor')->setMethods(['enqueue'])->getMock();
        $executor->expects($this->once())->method('enqueue')->willReturnArgument(0);

        $parser = $this->getMockBuilder('React\Mysql\Io\Parser')->disableOriginalConstructor()->getMock();

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->never())->method('addTimer');

        $conn = new Connection($stream, $executor, $parser, $loop, null);
        $conn->queryStream('SELECT 1');
    }

    public function testQueryStreamWillReturnStreamThatWillEmitEndEventAndStartIdleTimerWhenQueryCommandEmitsSuccess()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();

        $currentCommand = null;
        $executor = $this->getMockBuilder('React\Mysql\Io\Executor')->setMethods(['enqueue'])->getMock();
        $executor->expects($this->once())->method('enqueue')->willReturnCallback(function ($command) use (&$currentCommand) {
            return $currentCommand = $command;
        });

        $parser = $this->getMockBuilder('React\Mysql\Io\Parser')->disableOriginalConstructor()->getMock();

        $timer = $this->getMockBuilder('React\EventLoop\TimerInterface')->getMock();
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addTimer')->with(0.001, $this->anything())->willReturn($timer);
        $loop->expects($this->never())->method('cancelTimer');

        $connection = new Connection($stream, $executor, $parser, $loop, null);

        $this->assertNull($currentCommand);

        $stream = $connection->queryStream('SELECT 1');

        $stream->on('end', $this->expectCallableOnce());
        $stream->on('close', $this->expectCallableOnce());

        $this->assertNotNull($currentCommand);
        $currentCommand->emit('success');
    }

    public function testQueryStreamWillReturnStreamThatWillEmitErrorEventAndStartIdleTimerWhenQueryCommandEmitsError()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();

        $currentCommand = null;
        $executor = $this->getMockBuilder('React\Mysql\Io\Executor')->setMethods(['enqueue'])->getMock();
        $executor->expects($this->once())->method('enqueue')->willReturnCallback(function ($command) use (&$currentCommand) {
            return $currentCommand = $command;
        });

        $parser = $this->getMockBuilder('React\Mysql\Io\Parser')->disableOriginalConstructor()->getMock();

        $timer = $this->getMockBuilder('React\EventLoop\TimerInterface')->getMock();
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addTimer')->with(0.001, $this->anything())->willReturn($timer);
        $loop->expects($this->never())->method('cancelTimer');

        $connection = new Connection($stream, $executor, $parser, $loop, null);

        $this->assertNull($currentCommand);

        $stream = $connection->queryStream('SELECT 1');

        $stream->on('error', $this->expectCallableOnceWith($this->isInstanceOf('RuntimeException')));
        $stream->on('close', $this->expectCallableOnce());

        $this->assertNotNull($currentCommand);
        $currentCommand->emit('error', [new \RuntimeException()]);
    }

    public function testPingWillEnqueueOneCommand()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $stream->expects($this->never())->method('close');

        $executor = $this->getMockBuilder('React\Mysql\Io\Executor')->setMethods(['enqueue'])->getMock();
        $executor->expects($this->once())->method('enqueue')->willReturnArgument(0);

        $parser = $this->getMockBuilder('React\Mysql\Io\Parser')->disableOriginalConstructor()->getMock();

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->never())->method('addTimer');

        $conn = new Connection($stream, $executor, $parser, $loop, null);
        $conn->ping();
    }

    public function testPingWillReturnResolvedPromiseAndStartIdleTimerWhenPingCommandEmitsSuccess()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();

        $currentCommand = null;
        $executor = $this->getMockBuilder('React\Mysql\Io\Executor')->setMethods(['enqueue'])->getMock();
        $executor->expects($this->once())->method('enqueue')->willReturnCallback(function ($command) use (&$currentCommand) {
            return $currentCommand = $command;
        });

        $parser = $this->getMockBuilder('React\Mysql\Io\Parser')->disableOriginalConstructor()->getMock();

        $timer = $this->getMockBuilder('React\EventLoop\TimerInterface')->getMock();
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addTimer')->with(0.001, $this->anything())->willReturn($timer);
        $loop->expects($this->never())->method('cancelTimer');

        $connection = new Connection($stream, $executor, $parser, $loop, null);

        $this->assertNull($currentCommand);

        $promise = $connection->ping();

        $promise->then($this->expectCallableOnce());

        $this->assertNotNull($currentCommand);
        $currentCommand->emit('success');
    }

    public function testPingWillReturnRejectedPromiseAndStartIdleTimerWhenPingCommandEmitsError()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();

        $currentCommand = null;
        $executor = $this->getMockBuilder('React\Mysql\Io\Executor')->setMethods(['enqueue'])->getMock();
        $executor->expects($this->once())->method('enqueue')->willReturnCallback(function ($command) use (&$currentCommand) {
            return $currentCommand = $command;
        });

        $parser = $this->getMockBuilder('React\Mysql\Io\Parser')->disableOriginalConstructor()->getMock();

        $timer = $this->getMockBuilder('React\EventLoop\TimerInterface')->getMock();
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addTimer')->with(0.001, $this->anything())->willReturn($timer);
        $loop->expects($this->never())->method('cancelTimer');

        $connection = new Connection($stream, $executor, $parser, $loop, null);

        $this->assertNull($currentCommand);

        $promise = $connection->ping();

        $promise->then(null, $this->expectCallableOnce());

        $this->assertNotNull($currentCommand);
        $currentCommand->emit('error', [new \RuntimeException()]);
    }

    public function testQuitWillEnqueueOneCommand()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $executor = $this->getMockBuilder('React\Mysql\Io\Executor')->setMethods(['enqueue'])->getMock();
        $executor->expects($this->once())->method('enqueue')->willReturnArgument(0);

        $parser = $this->getMockBuilder('React\Mysql\Io\Parser')->disableOriginalConstructor()->getMock();

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->never())->method('addTimer');

        $conn = new Connection($stream, $executor, $parser, $loop, null);
        $conn->quit();
    }

    public function testQuitWillResolveBeforeEmittingCloseEventWhenQuitCommandEmitsSuccess()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();

        $pingCommand = null;
        $executor = $this->getMockBuilder('React\Mysql\Io\Executor')->setMethods(['enqueue'])->getMock();
        $executor->expects($this->once())->method('enqueue')->willReturnCallback(function ($command) use (&$pingCommand) {
            return $pingCommand = $command;
        });

        $parser = $this->getMockBuilder('React\Mysql\Io\Parser')->disableOriginalConstructor()->getMock();

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->never())->method('addTimer');

        $connection = new Connection($stream, $executor, $parser, $loop, null);

        $events = '';
        $connection->on('close', function () use (&$events) {
            $events .= 'closed.';
        });

        $this->assertEquals('', $events);

        $promise = $connection->quit();

        $promise->then(function () use (&$events) {
            $events .= 'fulfilled.';
        });

        $this->assertEquals('', $events);

        $this->assertNotNull($pingCommand);
        $pingCommand->emit('success');

        $this->assertEquals('fulfilled.closed.', $events);
    }

    public function testQuitWillRejectBeforeEmittingCloseEventWhenQuitCommandEmitsError()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();

        $pingCommand = null;
        $executor = $this->getMockBuilder('React\Mysql\Io\Executor')->setMethods(['enqueue'])->getMock();
        $executor->expects($this->once())->method('enqueue')->willReturnCallback(function ($command) use (&$pingCommand) {
            return $pingCommand = $command;
        });

        $parser = $this->getMockBuilder('React\Mysql\Io\Parser')->disableOriginalConstructor()->getMock();

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->never())->method('addTimer');

        $connection = new Connection($stream, $executor, $parser, $loop, null);

        $events = '';
        $connection->on('close', function () use (&$events) {
            $events .= 'closed.';
        });

        $this->assertEquals('', $events);

        $promise = $connection->quit();

        $promise->then(null, function () use (&$events) {
            $events .= 'rejected.';
        });

        $this->assertEquals('', $events);

        $this->assertNotNull($pingCommand);
        $pingCommand->emit('error', [new \RuntimeException()]);

        $this->assertEquals('rejected.closed.', $events);
    }

    public function testCloseWillEmitCloseEvent()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();

        $executor = $this->getMockBuilder('React\Mysql\Io\Executor')->getMock();
        $executor->expects($this->once())->method('isIdle')->willReturn(true);

        $parser = $this->getMockBuilder('React\Mysql\Io\Parser')->disableOriginalConstructor()->getMock();

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->never())->method('addTimer');

        $connection = new Connection($stream, $executor, $parser, $loop, null);

        $connection->on('close', $this->expectCallableOnce());

        $connection->close();
    }

    public function testCloseAfterIdleTimerWasStartedWillCancelIdleTimerAndEmitCloseEvent()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();

        $currentCommand = null;
        $executor = $this->getMockBuilder('React\Mysql\Io\Executor')->setMethods(['enqueue'])->getMock();
        $executor->expects($this->once())->method('enqueue')->willReturnCallback(function ($command) use (&$currentCommand) {
            return $currentCommand = $command;
        });

        $parser = $this->getMockBuilder('React\Mysql\Io\Parser')->disableOriginalConstructor()->getMock();

        $timer = $this->getMockBuilder('React\EventLoop\TimerInterface')->getMock();
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addTimer')->with(0.001, $this->anything())->willReturn($timer);
        $loop->expects($this->once())->method('cancelTimer')->with($timer);

        $connection = new Connection($stream, $executor, $parser, $loop, null);

        $this->assertNull($currentCommand);

        $connection->ping();

        $this->assertNotNull($currentCommand);
        $currentCommand->emit('success');

        $connection->on('close', $this->expectCallableOnce());

        $connection->close();
    }

    public function testQueryAfterQuitRejectsImmediately()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $executor = $this->getMockBuilder('React\Mysql\Io\Executor')->setMethods(['enqueue'])->getMock();
        $executor->expects($this->once())->method('enqueue')->willReturnArgument(0);

        $parser = $this->getMockBuilder('React\Mysql\Io\Parser')->disableOriginalConstructor()->getMock();

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $conn = new Connection($stream, $executor, $parser, $loop, null);
        $conn->quit();
        $promise = $conn->query('SELECT 1');

        $promise->then(null, $this->expectCallableOnceWith(
            $this->logicalAnd(
                $this->isInstanceOf('RuntimeException'),
                $this->callback(function (\RuntimeException $e) {
                    return $e->getMessage() === 'Connection closing (ENOTCONN)';
                }),
                $this->callback(function (\RuntimeException $e) {
                    return $e->getCode() === (defined('SOCKET_ENOTCONN') ? SOCKET_ENOTCONN : 107);
                })
            )
        ));
    }

    public function testQueryAfterCloseRejectsImmediately()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $executor = $this->getMockBuilder('React\Mysql\Io\Executor')->setMethods(['enqueue'])->getMock();
        $executor->expects($this->never())->method('enqueue');

        $parser = $this->getMockBuilder('React\Mysql\Io\Parser')->disableOriginalConstructor()->getMock();

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $conn = new Connection($stream, $executor, $parser, $loop, null);
        $conn->close();
        $promise = $conn->query('SELECT 1');

        $promise->then(null, $this->expectCallableOnceWith(
            $this->logicalAnd(
                $this->isInstanceOf('RuntimeException'),
                $this->callback(function (\RuntimeException $e) {
                    return $e->getMessage() === 'Connection closed (ENOTCONN)';
                }),
                $this->callback(function (\RuntimeException $e) {
                    return $e->getCode() === (defined('SOCKET_ENOTCONN') ? SOCKET_ENOTCONN : 107);
                })
            )
        ));
    }

    public function testQueryStreamAfterQuitThrows()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $executor = $this->getMockBuilder('React\Mysql\Io\Executor')->setMethods(['enqueue'])->getMock();
        $executor->expects($this->once())->method('enqueue')->willReturnArgument(0);

        $parser = $this->getMockBuilder('React\Mysql\Io\Parser')->disableOriginalConstructor()->getMock();

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $conn = new Connection($stream, $executor, $parser, $loop, null);
        $conn->quit();

        try {
            $conn->queryStream('SELECT 1');
        } catch (\RuntimeException $e) {
            $this->assertEquals('Connection closing (ENOTCONN)', $e->getMessage());
            $this->assertEquals(defined('SOCKET_ENOTCONN') ? SOCKET_ENOTCONN : 107, $e->getCode());
        }
    }

    public function testPingAfterQuitRejectsImmediately()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $executor = $this->getMockBuilder('React\Mysql\Io\Executor')->setMethods(['enqueue'])->getMock();
        $executor->expects($this->once())->method('enqueue')->willReturnArgument(0);

        $parser = $this->getMockBuilder('React\Mysql\Io\Parser')->disableOriginalConstructor()->getMock();

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $conn = new Connection($stream, $executor, $parser, $loop, null);
        $conn->quit();
        $promise = $conn->ping();

        $promise->then(null, $this->expectCallableOnceWith(
            $this->logicalAnd(
                $this->isInstanceOf('RuntimeException'),
                $this->callback(function (\RuntimeException $e) {
                    return $e->getMessage() === 'Connection closing (ENOTCONN)';
                }),
                $this->callback(function (\RuntimeException $e) {
                    return $e->getCode() === (defined('SOCKET_ENOTCONN') ? SOCKET_ENOTCONN : 107);
                })
            )
        ));
    }

    public function testQuitAfterQuitRejectsImmediately()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $executor = $this->getMockBuilder('React\Mysql\Io\Executor')->setMethods(['enqueue'])->getMock();
        $executor->expects($this->once())->method('enqueue')->willReturnArgument(0);

        $parser = $this->getMockBuilder('React\Mysql\Io\Parser')->disableOriginalConstructor()->getMock();

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $conn = new Connection($stream, $executor, $parser, $loop, null);
        $conn->quit();
        $promise = $conn->quit();

        $promise->then(null, $this->expectCallableOnceWith(
            $this->logicalAnd(
                $this->isInstanceOf('RuntimeException'),
                $this->callback(function (\RuntimeException $e) {
                    return $e->getMessage() === 'Connection closing (ENOTCONN)';
                }),
                $this->callback(function (\RuntimeException $e) {
                    return $e->getCode() === (defined('SOCKET_ENOTCONN') ? SOCKET_ENOTCONN : 107);
                })
            )
        ));
    }

    public function testCloseStreamEmitsErrorEvent()
    {
        $closeHandler = null;
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $stream->expects($this->exactly(2))->method('on')->withConsecutive(
            array('error', $this->anything()),
            array('close', $this->callback(function ($arg) use (&$closeHandler) {
                $closeHandler = $arg;
                return true;
            }))
        );
        $executor = $this->getMockBuilder('React\Mysql\Io\Executor')->setMethods(['enqueue'])->getMock();
        $executor->expects($this->never())->method('enqueue');

        $parser = $this->getMockBuilder('React\Mysql\Io\Parser')->disableOriginalConstructor()->getMock();

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $conn = new Connection($stream, $executor, $parser, $loop, null);
        $conn->on('error', $this->expectCallableOnceWith(
            $this->logicalAnd(
                $this->isInstanceOf('RuntimeException'),
                $this->callback(function (\RuntimeException $e) {
                    return $e->getMessage() === 'Connection closed by peer (ECONNRESET)';
                }),
                $this->callback(function (\RuntimeException $e) {
                    return $e->getCode() === (defined('SOCKET_ECONNRESET') ? SOCKET_ECONNRESET : 104);
                })
            )
        ));

        $this->assertNotNull($closeHandler);
        $closeHandler();
    }
}
