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
    public function testConnectionWillBeClosedWithErrorWhenPendingConnectionFails()
    {
        $deferred = new Deferred();
        $connection = new LazyConnection($deferred->promise());

        $connection->on('error', $this->expectCallableOnce());
        $connection->on('close', $this->expectCallableOnce());

        $deferred->reject(new \RuntimeException());
    }

    public function testConnectionWillBeClosedWithoutErrorWhenUnderlyingConnectionCloses()
    {
        $promise = new Promise(function () { });
        $base = new LazyConnection($promise);

        $connection = new LazyConnection(\React\Promise\resolve($base));

        $connection->on('error', $this->expectCallableNever());
        $connection->on('close', $this->expectCallableOnce());

        $base->close();
    }

    public function testConnectionWillForwardErrorFromUnderlyingConnection()
    {
        $promise = new Promise(function () { });
        $base = new LazyConnection($promise);

        $connection = new LazyConnection(\React\Promise\resolve($base));

        $connection->on('error', $this->expectCallableOnce());
        $connection->on('close', $this->expectCallableNever());

        $base->emit('error', [new \RuntimeException()]);
    }

    public function testQueryReturnsPendingPromiseWhenConnectionIsPending()
    {
        $promise = new Promise(function () { });
        $connection = new LazyConnection($promise);

        $ret = $connection->query('SELECT 1');

        $this->assertTrue($ret instanceof PromiseInterface);
        $ret->then($this->expectCallableNever(), $this->expectCallableNever());
    }

    public function testQueryWillQueryUnderlyingConnectionWhenResolved()
    {
        $base = $this->getMockBuilder('React\MySQL\ConnectionInterface')->getMock();
        $base->expects($this->once())->method('query')->with('SELECT 1');

        $connection = new LazyConnection(\React\Promise\resolve($base));

        $connection->query('SELECT 1');
    }

    public function testQueryWillRejectWhenUnderlyingConnectionRejects()
    {
        $deferred = new Deferred();
        $connection = new LazyConnection($deferred->promise());

        $ret = $connection->query('SELECT 1');
        $ret->then($this->expectCallableNever(), $this->expectCallableOnce());

        $deferred->reject(new \RuntimeException());
    }

    public function testQueryStreamReturnsReadableStreamWhenConnectionIsPending()
    {
        $promise = new Promise(function () { });
        $connection = new LazyConnection($promise);

        $ret = $connection->queryStream('SELECT 1');

        $this->assertTrue($ret instanceof ReadableStreamInterface);
        $this->assertTrue($ret->isReadable());
    }

    public function testQueryStreamWillReturnStreamFromUnderlyingConnectionWhenResolved()
    {
        $stream = new ThroughStream();
        $base = $this->getMockBuilder('React\MySQL\ConnectionInterface')->getMock();
        $base->expects($this->once())->method('queryStream')->with('SELECT 1')->willReturn($stream);

        $connection = new LazyConnection(\React\Promise\resolve($base));

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
        $connection = new LazyConnection($deferred->promise());

        $ret = $connection->queryStream('SELECT 1');

        $ret->on('error', $this->expectCallableOnce());
        $ret->on('close', $this->expectCallableOnce());

        $deferred->reject(new \RuntimeException());

        $this->assertFalse($ret->isReadable());
    }

    public function testPingReturnsPendingPromiseWhenConnectionIsPending()
    {
        $promise = new Promise(function () { });
        $connection = new LazyConnection($promise);

        $ret = $connection->ping();

        $this->assertTrue($ret instanceof PromiseInterface);
        $ret->then($this->expectCallableNever(), $this->expectCallableNever());
    }

    public function testPingWillPingUnderlyingConnectionWhenResolved()
    {
        $base = $this->getMockBuilder('React\MySQL\ConnectionInterface')->getMock();
        $base->expects($this->once())->method('ping');

        $connection = new LazyConnection(\React\Promise\resolve($base));

        $connection->ping();
    }

    public function testQuitReturnsPendingPromiseWhenConnectionIsPending()
    {
        $promise = new Promise(function () { });
        $connection = new LazyConnection($promise);

        $ret = $connection->quit();

        $this->assertTrue($ret instanceof PromiseInterface);
        $ret->then($this->expectCallableNever(), $this->expectCallableNever());
    }

    public function testQuitWillQuitUnderlyingConnectionWhenResolved()
    {
        $base = $this->getMockBuilder('React\MySQL\ConnectionInterface')->getMock();
        $base->expects($this->once())->method('quit');

        $connection = new LazyConnection(\React\Promise\resolve($base));

        $connection->quit();
    }

    public function testCloseCancelsPendingConnection()
    {
        $promise = new Promise(function () { }, $this->expectCallableOnce());
        $connection = new LazyConnection($promise);

        $connection->close();
    }

    public function testCloseTwiceWillCloseUnderlyingConnectionWhenResolved()
    {
        $base = $this->getMockBuilder('React\MySQL\ConnectionInterface')->getMock();
        $base->expects($this->once())->method('close');

        $connection = new LazyConnection(\React\Promise\resolve($base));

        $connection->close();
        $connection->close();
    }

    public function testCloseDoesNotEmitConnectionErrorFromAbortedConnection()
    {
        $promise = new Promise(function () { }, function () {
            throw new \RuntimeException();
        });
        $connection = new LazyConnection($promise);

        $connection->on('error', $this->expectCallableNever());
        $connection->on('close', $this->expectCallableOnce());

        $connection->close();
    }

    public function testCloseTwiceEmitsCloseEventOnceWhenConnectionIsPending()
    {
        $promise = new Promise(function () { });
        $connection = new LazyConnection($promise);

        $connection->on('error', $this->expectCallableNever());
        $connection->on('close', $this->expectCallableOnce());

        $connection->close();
        $connection->close();
    }

    public function testQueryReturnsRejectedPromiseAfterConnectionIsClosed()
    {
        $promise = new Promise(function () { });
        $connection = new LazyConnection($promise);

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
        $promise = new Promise(function () { });
        $connection = new LazyConnection($promise);

        $connection->close();
        $connection->queryStream('SELECT 1');
    }

    public function testPingReturnsRejectedPromiseAfterConnectionIsClosed()
    {
        $promise = new Promise(function () { });
        $connection = new LazyConnection($promise);

        $connection->close();
        $ret = $connection->ping();

        $this->assertTrue($ret instanceof PromiseInterface);
        $ret->then($this->expectCallableNever(), $this->expectCallableOnce());
    }

    public function testQuitReturnsRejectedPromiseAfterConnectionIsClosed()
    {
        $promise = new Promise(function () { });
        $connection = new LazyConnection($promise);

        $connection->close();
        $ret = $connection->quit();

        $this->assertTrue($ret instanceof PromiseInterface);
        $ret->then($this->expectCallableNever(), $this->expectCallableOnce());
    }
}
