<?php

namespace React\Tests\MySQL\Io;

use React\MySQL\Io\LazyConnection;
use React\Promise\Deferred;
use React\Promise\Promise;
use React\Promise\PromiseInterface;
use React\Tests\MySQL\BaseTestCase;
use React\Stream\ReadableStreamInterface;
use React\Stream\ThroughStream;
use React\MySQL\QueryResult;

class LazyConnectionTest extends BaseTestCase
{
    public function testPingWillNotCloseConnectionWhenPendingConnectionFails()
    {
        $deferred = new Deferred();
        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn($deferred->promise());
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $connection = new LazyConnection($factory, '', $loop);

        $connection->on('error', $this->expectCallableNever());
        $connection->on('close', $this->expectCallableNever());

        $connection->ping();

        $deferred->reject(new \RuntimeException());
    }
    public function testPingWillNotCloseConnectionWhenUnderlyingConnectionCloses()
    {
        $base = $this->getMockBuilder('React\MySQL\Io\LazyConnection')->setMethods(array('ping'))->disableOriginalConstructor()->getMock();
        $base->expects($this->once())->method('ping')->willReturn(\React\Promise\resolve());

        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($base));
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $connection = new LazyConnection($factory, '', $loop);

        $connection->on('error', $this->expectCallableNever());
        $connection->on('close', $this->expectCallableNever());

        $connection->ping();
        $base->close();
    }

    public function testPingWillCancelTimerWithoutClosingConnectionWhenUnderlyingConnectionCloses()
    {
        $base = $this->getMockBuilder('React\MySQL\Io\LazyConnection')->setMethods(array('ping'))->disableOriginalConstructor()->getMock();
        $base->expects($this->once())->method('ping')->willReturn(\React\Promise\resolve());

        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($base));

        $timer = $this->getMockBuilder('React\EventLoop\TimerInterface')->getMock();
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addTimer')->willReturn($timer);
        $loop->expects($this->once())->method('cancelTimer')->with($timer);

        $connection = new LazyConnection($factory, '', $loop);

        $connection->on('close', $this->expectCallableNever());

        $connection->ping();
        $base->close();
    }

    public function testPingWillNotForwardErrorFromUnderlyingConnection()
    {
        $base = $this->getMockBuilder('React\MySQL\Io\LazyConnection')->setMethods(array('ping'))->disableOriginalConstructor()->getMock();
        $base->expects($this->once())->method('ping')->willReturn(\React\Promise\resolve());

        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($base));
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $connection = new LazyConnection($factory, '', $loop);

        $connection->on('error', $this->expectCallableNever());
        $connection->on('close', $this->expectCallableNever());

        $connection->ping();

        $base->emit('error', [new \RuntimeException()]);
    }

    public function testPingFollowedByIdleTimerWillQuitUnderlyingConnection()
    {
        $base = $this->getMockBuilder('React\MySQL\Io\LazyConnection')->setMethods(array('ping', 'quit', 'close'))->disableOriginalConstructor()->getMock();
        $base->expects($this->once())->method('ping')->willReturn(\React\Promise\resolve());
        $base->expects($this->once())->method('quit')->willReturn(\React\Promise\resolve());
        $base->expects($this->never())->method('close');

        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($base));

        $timeout = null;
        $timer = $this->getMockBuilder('React\EventLoop\TimerInterface')->getMock();
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addTimer')->with($this->anything(), $this->callback(function ($cb) use (&$timeout) {
            $timeout = $cb;
            return true;
        }))->willReturn($timer);

        $connection = new LazyConnection($factory, '', $loop);

        $connection->on('close', $this->expectCallableNever());

        $connection->ping();

        $this->assertNotNull($timeout);
        $timeout();
    }

    public function testPingFollowedByIdleTimerWillCloseUnderlyingConnectionWhenQuitFails()
    {
        $base = $this->getMockBuilder('React\MySQL\Io\LazyConnection')->setMethods(array('ping', 'quit', 'close'))->disableOriginalConstructor()->getMock();
        $base->expects($this->once())->method('ping')->willReturn(\React\Promise\resolve());
        $base->expects($this->once())->method('quit')->willReturn(\React\Promise\reject());
        $base->expects($this->once())->method('close');

        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($base));

        $timeout = null;
        $timer = $this->getMockBuilder('React\EventLoop\TimerInterface')->getMock();
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addTimer')->with($this->anything(), $this->callback(function ($cb) use (&$timeout) {
            $timeout = $cb;
            return true;
        }))->willReturn($timer);

        $connection = new LazyConnection($factory, '', $loop);

        $connection->on('close', $this->expectCallableNever());

        $connection->ping();

        $this->assertNotNull($timeout);
        $timeout();
    }

    public function testPingAfterIdleTimerWillCloseUnderlyingConnectionBeforeCreatingSecondConnection()
    {
        $base = $this->getMockBuilder('React\MySQL\Io\LazyConnection')->setMethods(array('ping', 'quit', 'close'))->disableOriginalConstructor()->getMock();
        $base->expects($this->once())->method('ping')->willReturn(\React\Promise\resolve());
        $base->expects($this->once())->method('quit')->willReturn(new Promise(function () { }));
        $base->expects($this->once())->method('close');

        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->exactly(2))->method('createConnection')->willReturnOnConsecutiveCalls(
            \React\Promise\resolve($base),
            new Promise(function () { })
        );

        $timeout = null;
        $timer = $this->getMockBuilder('React\EventLoop\TimerInterface')->getMock();
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addTimer')->with($this->anything(), $this->callback(function ($cb) use (&$timeout) {
            $timeout = $cb;
            return true;
        }))->willReturn($timer);

        $connection = new LazyConnection($factory, '', $loop);

        $connection->on('close', $this->expectCallableNever());

        $connection->ping();

        $this->assertNotNull($timeout);
        $timeout();

        $connection->ping();
    }


    public function testQueryReturnsPendingPromiseAndWillNotStartTimerWhenConnectionIsPending()
    {
        $deferred = new Deferred();
        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn($deferred->promise());

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->never())->method('addTimer');

        $connection = new LazyConnection($factory, '', $loop);

        $ret = $connection->query('SELECT 1');

        $this->assertTrue($ret instanceof PromiseInterface);
        $ret->then($this->expectCallableNever(), $this->expectCallableNever());
    }

    public function testQueryWillQueryUnderlyingConnectionWhenResolved()
    {
        $base = $this->getMockBuilder('React\MySQL\ConnectionInterface')->getMock();
        $base->expects($this->once())->method('query')->with('SELECT 1')->willReturn(new Promise(function () { }));

        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($base));
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $connection = new LazyConnection($factory, '', $loop);

        $connection->query('SELECT 1');
    }

    public function testQueryWillResolveAndStartTimerWithDefaultIntervalWhenQueryFromUnderlyingConnectionResolves()
    {
        $result = new QueryResult();

        $base = $this->getMockBuilder('React\MySQL\ConnectionInterface')->getMock();
        $base->expects($this->once())->method('query')->with('SELECT 1')->willReturn(\React\Promise\resolve($result));

        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($base));

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addTimer')->with(60.0, $this->anything());

        $connection = new LazyConnection($factory, '', $loop);

        $ret = $connection->query('SELECT 1');
        $ret->then($this->expectCallableOnceWith($result), $this->expectCallableNever());
    }

    public function testQueryWillResolveAndStartTimerWithIntervalFromIdleParameterWhenQueryFromUnderlyingConnectionResolves()
    {
        $result = new QueryResult();

        $base = $this->getMockBuilder('React\MySQL\ConnectionInterface')->getMock();
        $base->expects($this->once())->method('query')->with('SELECT 1')->willReturn(\React\Promise\resolve($result));

        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($base));

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addTimer')->with(2.5, $this->anything());

        $connection = new LazyConnection($factory, 'mysql://localhost?idle=2.5', $loop);

        $ret = $connection->query('SELECT 1');
        $ret->then($this->expectCallableOnceWith($result), $this->expectCallableNever());
    }

    public function testQueryWillResolveWithoutStartingTimerWhenQueryFromUnderlyingConnectionResolvesAndIdleParameterIsNegative()
    {
        $result = new QueryResult();

        $base = $this->getMockBuilder('React\MySQL\ConnectionInterface')->getMock();
        $base->expects($this->once())->method('query')->with('SELECT 1')->willReturn(\React\Promise\resolve($result));

        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($base));

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->never())->method('addTimer');

        $connection = new LazyConnection($factory, 'mysql://localhost?idle=-1', $loop);

        $ret = $connection->query('SELECT 1');
        $ret->then($this->expectCallableOnceWith($result), $this->expectCallableNever());
    }

    public function testQueryBeforePingWillResolveWithoutStartingTimerWhenQueryFromUnderlyingConnectionResolvesBecausePingIsStillPending()
    {
        $result = new QueryResult();
        $deferred = new Deferred();

        $base = $this->getMockBuilder('React\MySQL\ConnectionInterface')->getMock();
        $base->expects($this->once())->method('query')->with('SELECT 1')->willReturn($deferred->promise());
        $base->expects($this->once())->method('ping')->willReturn(new Promise(function () { }));

        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($base));

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->never())->method('addTimer');

        $connection = new LazyConnection($factory, '', $loop);

        $ret = $connection->query('SELECT 1');
        $connection->ping();

        $deferred->resolve($result);

        $ret->then($this->expectCallableOnceWith($result), $this->expectCallableNever());
    }

    public function testQueryAfterPingWillCancelTimerAgainWhenPingFromUnderlyingConnectionResolved()
    {
        $base = $this->getMockBuilder('React\MySQL\ConnectionInterface')->getMock();
        $base->expects($this->once())->method('ping')->willReturn(\React\Promise\resolve());
        $base->expects($this->once())->method('query')->with('SELECT 1')->willReturn(new Promise(function () { }));

        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($base));

        $timer = $this->getMockBuilder('React\EventLoop\TimerInterface')->getMock();
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addTimer')->willReturn($timer);
        $loop->expects($this->once())->method('cancelTimer')->with($timer);

        $connection = new LazyConnection($factory, '', $loop);

        $connection->ping();
        $connection->query('SELECT 1');
    }

    public function testQueryWillRejectAndStartTimerWhenQueryFromUnderlyingConnectionRejects()
    {
        $error = new \RuntimeException();

        $base = $this->getMockBuilder('React\MySQL\ConnectionInterface')->getMock();
        $base->expects($this->once())->method('query')->with('SELECT 1')->willReturn(\React\Promise\reject($error));

        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($base));

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addTimer');

        $connection = new LazyConnection($factory, '', $loop);

        $ret = $connection->query('SELECT 1');
        $ret->then($this->expectCallableNever(), $this->expectCallableOnceWith($error));
    }

    public function testQueryWillRejectWithoutStartingTimerWhenUnderlyingConnectionRejects()
    {
        $deferred = new Deferred();
        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn($deferred->promise());

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->never())->method('addTimer');

        $connection = new LazyConnection($factory, '', $loop);

        $ret = $connection->query('SELECT 1');
        $ret->then($this->expectCallableNever(), $this->expectCallableOnce());

        $deferred->reject(new \RuntimeException());
    }

    public function testQueryStreamReturnsReadableStreamWhenConnectionIsPending()
    {
        $promise = new Promise(function () { });
        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn($promise);
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $connection = new LazyConnection($factory, '', $loop);

        $ret = $connection->queryStream('SELECT 1');

        $this->assertTrue($ret instanceof ReadableStreamInterface);
        $this->assertTrue($ret->isReadable());
    }

    public function testQueryStreamWillReturnStreamFromUnderlyingConnectionWithoutStartingTimerWhenResolved()
    {
        $stream = new ThroughStream();
        $base = $this->getMockBuilder('React\MySQL\ConnectionInterface')->getMock();
        $base->expects($this->once())->method('queryStream')->with('SELECT 1')->willReturn($stream);

        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($base));

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->never())->method('addTimer');

        $connection = new LazyConnection($factory, '', $loop);

        $ret = $connection->queryStream('SELECT 1');

        $ret->on('data', $this->expectCallableOnceWith('hello'));
        $stream->write('hello');

        $this->assertTrue($ret->isReadable());
    }

    public function testQueryStreamWillReturnStreamFromUnderlyingConnectionAndStartTimerWhenResolvedAndClosed()
    {
        $stream = new ThroughStream();
        $base = $this->getMockBuilder('React\MySQL\ConnectionInterface')->getMock();
        $base->expects($this->once())->method('queryStream')->with('SELECT 1')->willReturn($stream);

        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($base));

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addTimer');

        $connection = new LazyConnection($factory, '', $loop);

        $ret = $connection->queryStream('SELECT 1');

        $ret->on('data', $this->expectCallableOnceWith('hello'));
        $stream->write('hello');

        $ret->on('close', $this->expectCallableOnce());
        $stream->close();

        $this->assertFalse($ret->isReadable());
    }

    public function testQueryStreamWillCloseStreamWithErrorWhenUnderlyingConnectionRejects()
    {
        $deferred = new Deferred();
        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn($deferred->promise());
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $connection = new LazyConnection($factory, '', $loop);

        $ret = $connection->queryStream('SELECT 1');

        $ret->on('error', $this->expectCallableOnce());
        $ret->on('close', $this->expectCallableOnce());

        $deferred->reject(new \RuntimeException());

        $this->assertFalse($ret->isReadable());
    }

    public function testPingReturnsPendingPromiseWhenConnectionIsPending()
    {
        $promise = new Promise(function () { });
        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn($promise);
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $connection = new LazyConnection($factory, '', $loop);

        $ret = $connection->ping();

        $this->assertTrue($ret instanceof PromiseInterface);
        $ret->then($this->expectCallableNever(), $this->expectCallableNever());
    }

    public function testPingWillPingUnderlyingConnectionWhenResolved()
    {
        $base = $this->getMockBuilder('React\MySQL\ConnectionInterface')->getMock();
        $base->expects($this->once())->method('ping')->willReturn(new Promise(function () { }));

        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($base));
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $connection = new LazyConnection($factory, '', $loop);

        $connection->ping();
    }

    public function testPingTwiceWillBothRejectWithSameErrorWhenUnderlyingConnectionRejects()
    {
        $error = new \RuntimeException();
        $deferred = new Deferred();

        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn($deferred->promise());
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $connection = new LazyConnection($factory, '', $loop);

        $connection->ping()->then($this->expectCallableNever(), $this->expectCallableOnceWith($error));
        $connection->ping()->then($this->expectCallableNever(), $this->expectCallableOnceWith($error));

        $deferred->reject($error);
    }

    public function testPingWillTryToCreateNewUnderlyingConnectionAfterPreviousPingFailedToCreateUnderlyingConnection()
    {
        $error = new \RuntimeException();

        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->exactly(2))->method('createConnection')->willReturn(\React\Promise\reject($error));
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $connection = new LazyConnection($factory, '', $loop);

        $connection->ping()->then($this->expectCallableNever(), $this->expectCallableOnceWith($error));
        $connection->ping()->then($this->expectCallableNever(), $this->expectCallableOnceWith($error));
    }

    public function testPingWillResolveAndStartTimerWhenPingFromUnderlyingConnectionResolves()
    {
        $base = $this->getMockBuilder('React\MySQL\ConnectionInterface')->getMock();
        $base->expects($this->once())->method('ping')->willReturn(\React\Promise\resolve());

        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($base));

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addTimer');

        $connection = new LazyConnection($factory, '', $loop);

        $ret = $connection->ping();
        $ret->then($this->expectCallableOnce(), $this->expectCallableNever());
    }

    public function testPingWillRejectAndStartTimerWhenPingFromUnderlyingConnectionRejects()
    {
        $error = new \RuntimeException();

        $base = $this->getMockBuilder('React\MySQL\ConnectionInterface')->getMock();
        $base->expects($this->once())->method('ping')->willReturn(\React\Promise\reject($error));

        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($base));

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addTimer');

        $connection = new LazyConnection($factory, '', $loop);

        $ret = $connection->ping();
        $ret->then($this->expectCallableNever(), $this->expectCallableOnceWith($error));
    }

    public function testPingWillRejectAndNotStartIdleTimerWhenPingFromUnderlyingConnectionRejectsBecauseConnectionIsDead()
    {
        $error = new \RuntimeException();

        $base = $this->getMockBuilder('React\MySQL\Io\LazyConnection')->setMethods(array('ping', 'close'))->disableOriginalConstructor()->getMock();
        $base->expects($this->once())->method('ping')->willReturnCallback(function () use ($base, $error) {
            $base->emit('close');
            return \React\Promise\reject($error);
        });
        $base->expects($this->never())->method('close');

        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($base));

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->never())->method('addTimer');

        $connection = new LazyConnection($factory, '', $loop);

        $ret = $connection->ping();
        $ret->then($this->expectCallableNever(), $this->expectCallableOnceWith($error));
    }

    public function testQuitResolvesAndEmitsCloseImmediatelyWhenConnectionIsNotAlreadyPending()
    {
        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->never())->method('createConnection');
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $connection = new LazyConnection($factory, '', $loop);

        $connection->on('error', $this->expectCallableNever());
        $connection->on('close', $this->expectCallableOnce());

        $ret = $connection->quit();

        $this->assertTrue($ret instanceof PromiseInterface);
        $ret->then($this->expectCallableOnce(), $this->expectCallableNever());
    }

    public function testQuitAfterPingReturnsPendingPromiseWhenConnectionIsPending()
    {
        $promise = new Promise(function () { });
        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn($promise);
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $connection = new LazyConnection($factory, '', $loop);

        $connection->ping();
        $ret = $connection->quit();

        $this->assertTrue($ret instanceof PromiseInterface);
        $ret->then($this->expectCallableNever(), $this->expectCallableNever());
    }

    public function testQuitAfterPingWillQuitUnderlyingConnectionWhenResolved()
    {
        $base = $this->getMockBuilder('React\MySQL\ConnectionInterface')->getMock();
        $base->expects($this->once())->method('ping')->willReturn(\React\Promise\resolve());
        $base->expects($this->once())->method('quit')->willReturn(new Promise(function () { }));

        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($base));
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $connection = new LazyConnection($factory, '', $loop);

        $connection->ping();
        $connection->quit();
    }

    public function testQuitAfterPingResolvesAndEmitsCloseWhenUnderlyingConnectionQuits()
    {
        $base = $this->getMockBuilder('React\MySQL\ConnectionInterface')->getMock();
        $base->expects($this->once())->method('ping')->willReturn(\React\Promise\resolve());
        $base->expects($this->once())->method('quit')->willReturn(\React\Promise\resolve());

        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($base));
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $connection = new LazyConnection($factory, '', $loop);

        $connection->on('close', $this->expectCallableOnce());

        $connection->ping();
        $ret = $connection->quit();

        $this->assertTrue($ret instanceof PromiseInterface);
        $ret->then($this->expectCallableOnce(), $this->expectCallableNever());
    }

    public function testQuitAfterPingRejectsAndEmitsCloseWhenUnderlyingConnectionFailsToQuit()
    {
        $error = new \RuntimeException();
        $base = $this->getMockBuilder('React\MySQL\ConnectionInterface')->getMock();
        $base->expects($this->once())->method('ping')->willReturn(\React\Promise\resolve());
        $base->expects($this->once())->method('quit')->willReturn(\React\Promise\reject($error));

        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($base));
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $connection = new LazyConnection($factory, '', $loop);

        $connection->on('close', $this->expectCallableOnce());

        $connection->ping();
        $ret = $connection->quit();

        $this->assertTrue($ret instanceof PromiseInterface);
        $ret->then($this->expectCallableNever(), $this->expectCallableOnceWith($error));
    }

    public function testCloseEmitsCloseImmediatelyWhenConnectionIsNotAlreadyPending()
    {
        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->never())->method('createConnection');
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $connection = new LazyConnection($factory, '', $loop);

        $connection->on('error', $this->expectCallableNever());
        $connection->on('close', $this->expectCallableOnce());

        $connection->close();
    }

    public function testCloseAfterPingCancelsPendingConnection()
    {
        $deferred = new Deferred($this->expectCallableOnce());
        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn($deferred->promise());
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $connection = new LazyConnection($factory, '', $loop);

        $connection->ping();
        $connection->close();
    }

    public function testCloseTwiceAfterPingWillCloseUnderlyingConnectionWhenResolved()
    {
        $base = $this->getMockBuilder('React\MySQL\ConnectionInterface')->getMock();
        $base->expects($this->once())->method('ping')->willReturn(\React\Promise\resolve());
        $base->expects($this->once())->method('close');

        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($base));
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $connection = new LazyConnection($factory, '', $loop);

        $connection->ping();
        $connection->close();
        $connection->close();
    }

    public function testCloseAfterPingDoesNotEmitConnectionErrorFromAbortedConnection()
    {
        $promise = new Promise(function () { }, function () {
            throw new \RuntimeException();
        });

        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn($promise);
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $connection = new LazyConnection($factory, '', $loop);

        $connection->on('error', $this->expectCallableNever());
        $connection->on('close', $this->expectCallableOnce());

        $connection->ping();
        $connection->close();
    }

    public function testCloseAfterPingWillCancelTimerWhenPingFromUnderlyingConnectionResolves()
    {
        $base = $this->getMockBuilder('React\MySQL\ConnectionInterface')->getMock();
        $base->expects($this->once())->method('ping')->willReturn(\React\Promise\resolve());

        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($base));

        $timer = $this->getMockBuilder('React\EventLoop\TimerInterface')->getMock();
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addTimer')->willReturn($timer);
        $loop->expects($this->once())->method('cancelTimer')->with($timer);

        $connection = new LazyConnection($factory, '', $loop);

        $connection->ping()->then($this->expectCallableOnce(), $this->expectCallableNever());
        $connection->close();
    }

    public function testCloseAfterPingHasResolvedWillCloseUnderlyingConnectionWithoutTryingToCancelConnection()
    {
        $base = $this->getMockBuilder('React\MySQL\Io\LazyConnection')->setMethods(array('ping', 'close'))->disableOriginalConstructor()->getMock();
        $base->expects($this->once())->method('ping')->willReturn(\React\Promise\resolve());
        $base->expects($this->once())->method('close')->willReturnCallback(function () use ($base) {
            $base->emit('close');
        });

        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($base));

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $connection = new LazyConnection($factory, '', $loop);

        $connection->ping();
        $connection->close();
    }

    public function testCloseAfterQuitAfterPingWillCloseUnderlyingConnectionWhenQuitIsStillPending()
    {
        $base = $this->getMockBuilder('React\MySQL\ConnectionInterface')->getMock();
        $base->expects($this->once())->method('ping')->willReturn(\React\Promise\resolve());
        $base->expects($this->once())->method('quit')->willReturn(new Promise(function () { }));
        $base->expects($this->once())->method('close');

        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($base));
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $connection = new LazyConnection($factory, '', $loop);

        $connection->ping();
        $connection->quit();
        $connection->close();
    }

    public function testCloseAfterPingAfterIdleTimeoutWillCloseUnderlyingConnectionWhenQuitIsStillPending()
    {
        $base = $this->getMockBuilder('React\MySQL\ConnectionInterface')->getMock();
        $base->expects($this->once())->method('ping')->willReturn(\React\Promise\resolve());
        $base->expects($this->once())->method('quit')->willReturn(new Promise(function () { }));
        $base->expects($this->once())->method('close');

        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($base));

        $timeout = null;
        $timer = $this->getMockBuilder('React\EventLoop\TimerInterface')->getMock();
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addTimer')->with($this->anything(), $this->callback(function ($cb) use (&$timeout) {
            $timeout = $cb;
            return true;
        }))->willReturn($timer);

        $connection = new LazyConnection($factory, '', $loop);

        $connection->ping();

        $this->assertNotNull($timeout);
        $timeout();

        $connection->close();
    }

    public function testCloseTwiceAfterPingEmitsCloseEventOnceWhenConnectionIsPending()
    {
        $promise = new Promise(function () { });
        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn($promise);
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $connection = new LazyConnection($factory, '', $loop);

        $connection->on('error', $this->expectCallableNever());
        $connection->on('close', $this->expectCallableOnce());

        $connection->ping();
        $connection->close();
        $connection->close();
    }

    public function testQueryReturnsRejectedPromiseAfterConnectionIsClosed()
    {
        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->never())->method('createConnection');
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $connection = new LazyConnection($factory, '', $loop);

        $connection->close();
        $ret = $connection->query('SELECT 1');

        $this->assertTrue($ret instanceof PromiseInterface);
        $ret->then($this->expectCallableNever(), $this->expectCallableOnce());
    }

    /**
     * @expectedException React\MySQL\Exception
     */
    public function testQueryStreamThrowsAfterConnectionIsClosed()
    {
        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->never())->method('createConnection');
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $connection = new LazyConnection($factory, '', $loop);

        $connection->close();
        $connection->queryStream('SELECT 1');
    }

    public function testPingReturnsRejectedPromiseAfterConnectionIsClosed()
    {
        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->never())->method('createConnection');
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $connection = new LazyConnection($factory, '', $loop);

        $connection->close();
        $ret = $connection->ping();

        $this->assertTrue($ret instanceof PromiseInterface);
        $ret->then($this->expectCallableNever(), $this->expectCallableOnce());
    }

    public function testQuitReturnsRejectedPromiseAfterConnectionIsClosed()
    {
        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->never())->method('createConnection');
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $connection = new LazyConnection($factory, '', $loop);

        $connection->close();
        $ret = $connection->quit();

        $this->assertTrue($ret instanceof PromiseInterface);
        $ret->then($this->expectCallableNever(), $this->expectCallableOnce());
    }
}
