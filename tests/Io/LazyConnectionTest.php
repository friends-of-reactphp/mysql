<?php

namespace React\Tests\MySQL\Io;

use React\MySQL\Io\LazyConnection;
use React\Promise\Deferred;
use React\Promise\Promise;
use React\Promise\PromiseInterface;
use React\Tests\MySQL\BaseTestCase;
use React\Stream\ReadableStreamInterface;
use React\Stream\ThroughStream;

class LazyConnectionTest extends BaseTestCase
{
    public function testPingWillCloseConnectionWithErrorWhenPendingConnectionFails()
    {
        $deferred = new Deferred();
        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn($deferred->promise());
        $connection = new LazyConnection($factory, '');

        $connection->on('error', $this->expectCallableOnce());
        $connection->on('close', $this->expectCallableOnce());

        $connection->ping();

        $deferred->reject(new \RuntimeException());
    }

    public function testPingWillCloseConnectionWithoutErrorWhenUnderlyingConnectionCloses()
    {
        $promise = new Promise(function () { });
        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn($promise);
        $base = new LazyConnection($factory, '');

        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($base));
        $connection = new LazyConnection($factory, '');

        $connection->on('error', $this->expectCallableNever());
        $connection->on('close', $this->expectCallableOnce());

        $connection->ping();
        $base->close();
    }

    public function testPingWillForwardErrorFromUnderlyingConnection()
    {
        $promise = new Promise(function () { });
        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn($promise);
        $base = new LazyConnection($factory, '');

        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($base));
        $connection = new LazyConnection($factory, '');

        $connection->on('error', $this->expectCallableOnce());
        $connection->on('close', $this->expectCallableNever());

        $connection->ping();

        $base->emit('error', [new \RuntimeException()]);
    }

    public function testQueryReturnsPendingPromiseWhenConnectionIsPending()
    {
        $deferred = new Deferred();
        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn($deferred->promise());
        $connection = new LazyConnection($factory, '');

        $ret = $connection->query('SELECT 1');

        $this->assertTrue($ret instanceof PromiseInterface);
        $ret->then($this->expectCallableNever(), $this->expectCallableNever());
    }

    public function testQueryWillQueryUnderlyingConnectionWhenResolved()
    {
        $base = $this->getMockBuilder('React\MySQL\ConnectionInterface')->getMock();
        $base->expects($this->once())->method('query')->with('SELECT 1');

        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($base));
        $connection = new LazyConnection($factory, '');

        $connection->query('SELECT 1');
    }

    public function testQueryWillRejectWhenUnderlyingConnectionRejects()
    {
        $deferred = new Deferred();
        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn($deferred->promise());
        $connection = new LazyConnection($factory, '');

        $ret = $connection->query('SELECT 1');
        $ret->then($this->expectCallableNever(), $this->expectCallableOnce());

        $deferred->reject(new \RuntimeException());
    }

    public function testQueryStreamReturnsReadableStreamWhenConnectionIsPending()
    {
        $promise = new Promise(function () { });
        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn($promise);
        $connection = new LazyConnection($factory, '');

        $ret = $connection->queryStream('SELECT 1');

        $this->assertTrue($ret instanceof ReadableStreamInterface);
        $this->assertTrue($ret->isReadable());
    }

    public function testQueryStreamWillReturnStreamFromUnderlyingConnectionWhenResolved()
    {
        $stream = new ThroughStream();
        $base = $this->getMockBuilder('React\MySQL\ConnectionInterface')->getMock();
        $base->expects($this->once())->method('queryStream')->with('SELECT 1')->willReturn($stream);

        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($base));
        $connection = new LazyConnection($factory, '');

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
        $connection = new LazyConnection($factory, '');

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
        $connection = new LazyConnection($factory, '');

        $ret = $connection->ping();

        $this->assertTrue($ret instanceof PromiseInterface);
        $ret->then($this->expectCallableNever(), $this->expectCallableNever());
    }

    public function testPingWillPingUnderlyingConnectionWhenResolved()
    {
        $base = $this->getMockBuilder('React\MySQL\ConnectionInterface')->getMock();
        $base->expects($this->once())->method('ping');

        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($base));
        $connection = new LazyConnection($factory, '');

        $connection->ping();
    }

    public function testQuitResolvesAndEmitsCloseImmediatelyWhenConnectionIsNotAlreadyPending()
    {
        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->never())->method('createConnection');
        $connection = new LazyConnection($factory, '');

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
        $connection = new LazyConnection($factory, '');

        $connection->ping();
        $ret = $connection->quit();

        $this->assertTrue($ret instanceof PromiseInterface);
        $ret->then($this->expectCallableNever(), $this->expectCallableNever());
    }

    public function testQuitAfterPingWillQuitUnderlyingConnectionWhenResolved()
    {
        $base = $this->getMockBuilder('React\MySQL\ConnectionInterface')->getMock();
        $base->expects($this->once())->method('quit');

        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($base));
        $connection = new LazyConnection($factory, '');

        $connection->ping();
        $connection->quit();
    }

    public function testCloseEmitsCloseImmediatelyWhenConnectionIsNotAlreadyPending()
    {
        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->never())->method('createConnection');
        $connection = new LazyConnection($factory, '');

        $connection->on('error', $this->expectCallableNever());
        $connection->on('close', $this->expectCallableOnce());

        $connection->close();
    }

    public function testCloseAfterPingCancelsPendingConnection()
    {
        $deferred = new Deferred($this->expectCallableOnce());
        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn($deferred->promise());
        $connection = new LazyConnection($factory, '');

        $connection->ping();
        $connection->close();
    }

    public function testCloseTwiceAfterPingWillCloseUnderlyingConnectionWhenResolved()
    {
        $base = $this->getMockBuilder('React\MySQL\ConnectionInterface')->getMock();
        $base->expects($this->once())->method('close');

        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($base));
        $connection = new LazyConnection($factory, '');

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
        $connection = new LazyConnection($factory, '');

        $connection->on('error', $this->expectCallableNever());
        $connection->on('close', $this->expectCallableOnce());

        $connection->ping();
        $connection->close();
    }

    public function testCloseTwiceAfterPingEmitsCloseEventOnceWhenConnectionIsPending()
    {
        $promise = new Promise(function () { });
        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn($promise);
        $connection = new LazyConnection($factory, '');

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
        $connection = new LazyConnection($factory, '');

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
        $connection = new LazyConnection($factory, '');

        $connection->close();
        $connection->queryStream('SELECT 1');
    }

    public function testPingReturnsRejectedPromiseAfterConnectionIsClosed()
    {
        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->never())->method('createConnection');
        $connection = new LazyConnection($factory, '');

        $connection->close();
        $ret = $connection->ping();

        $this->assertTrue($ret instanceof PromiseInterface);
        $ret->then($this->expectCallableNever(), $this->expectCallableOnce());
    }

    public function testQuitReturnsRejectedPromiseAfterConnectionIsClosed()
    {
        $factory = $this->getMockBuilder('React\MySQL\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->never())->method('createConnection');
        $connection = new LazyConnection($factory, '');

        $connection->close();
        $ret = $connection->quit();

        $this->assertTrue($ret instanceof PromiseInterface);
        $ret->then($this->expectCallableNever(), $this->expectCallableOnce());
    }
}
