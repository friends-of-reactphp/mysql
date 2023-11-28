<?php

namespace React\Tests\Mysql;

use React\Mysql\Io\Connection;
use React\Mysql\MysqlClient;
use React\Mysql\MysqlResult;
use React\Promise\Deferred;
use React\Promise\Promise;
use React\Promise\PromiseInterface;
use React\Stream\ReadableStreamInterface;
use React\Stream\ThroughStream;

class MysqlClientTest extends BaseTestCase
{
    public function testConstructWithoutConnectorAndLoopAssignsConnectorAndLoopAutomatically()
    {
        $mysql = new MysqlClient('localhost');

        $ref = new \ReflectionProperty($mysql, 'factory');
        $ref->setAccessible(true);
        $factory = $ref->getValue($mysql);

        $ref = new \ReflectionProperty($factory, 'connector');
        $ref->setAccessible(true);
        $connector = $ref->getValue($factory);

        $this->assertInstanceOf('React\Socket\ConnectorInterface', $connector);

        $ref = new \ReflectionProperty($factory, 'loop');
        $ref->setAccessible(true);
        $loop = $ref->getValue($factory);

        $this->assertInstanceOf('React\EventLoop\LoopInterface', $loop);
    }

    public function testConstructWithConnectorAndLoopAssignsGivenConnectorAndLoop()
    {
        $connector = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $mysql = new MysqlClient('localhost', $connector, $loop);

        $ref = new \ReflectionProperty($mysql, 'factory');
        $ref->setAccessible(true);
        $factory = $ref->getValue($mysql);

        $ref = new \ReflectionProperty($factory, 'connector');
        $ref->setAccessible(true);

        $this->assertSame($connector, $ref->getValue($factory));

        $ref = new \ReflectionProperty($factory, 'loop');
        $ref->setAccessible(true);

        $this->assertSame($loop, $ref->getValue($factory));
    }

    public function testPingWillNotCloseConnectionWhenPendingConnectionFails()
    {
        $deferred = new Deferred();
        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn($deferred->promise());
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $connection = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($connection, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($connection, $factory);

        $connection->on('error', $this->expectCallableNever());
        $connection->on('close', $this->expectCallableNever());

        $promise = $connection->ping();

        $promise->then(null, $this->expectCallableOnce()); // avoid reporting unhandled rejection

        $deferred->reject(new \RuntimeException());
    }

    public function testConnectionCloseEventAfterPingWillNotEmitCloseEvent()
    {
        $base = $this->getMockBuilder('React\Mysql\Io\Connection')->setMethods(['ping', 'close'])->disableOriginalConstructor()->getMock();
        $base->expects($this->once())->method('ping')->willReturn(\React\Promise\resolve(null));

        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($base));
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $connection = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($connection, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($connection, $factory);

        $connection->on('error', $this->expectCallableNever());
        $connection->on('close', $this->expectCallableNever());

        $connection->ping();

        assert($base instanceof Connection);
        $base->emit('close');
    }

    public function testConnectionErrorEventAfterPingWillNotEmitErrorEvent()
    {
        $base = $this->getMockBuilder('React\Mysql\Io\Connection')->setMethods(['ping'])->disableOriginalConstructor()->getMock();
        $base->expects($this->once())->method('ping')->willReturn(\React\Promise\resolve(null));

        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($base));
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $connection = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($connection, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($connection, $factory);

        $connection->on('error', $this->expectCallableNever());
        $connection->on('close', $this->expectCallableNever());

        $connection->ping();

        assert($base instanceof Connection);
        $base->emit('error', [new \RuntimeException()]);
    }

    public function testPingAfterConnectionIsInClosingStateDueToIdleTimerWillCloseConnectionBeforeCreatingSecondConnection()
    {
        $base = $this->getMockBuilder('React\Mysql\Io\Connection')->setMethods(['ping', 'quit', 'close'])->disableOriginalConstructor()->getMock();
        $base->expects($this->once())->method('ping')->willReturn(\React\Promise\resolve(null));
        $base->expects($this->never())->method('quit');
        $base->expects($this->once())->method('close');

        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->exactly(2))->method('createConnection')->willReturnOnConsecutiveCalls(
            \React\Promise\resolve($base),
            new Promise(function () { })
        );

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $connection = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($connection, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($connection, $factory);

        $connection->on('close', $this->expectCallableNever());

        $connection->ping();

        // emulate triggering idle timer by setting connection state to closing
        $base->state = Connection::STATE_CLOSING;

        $connection->ping();
    }

    public function testQueryWillCreateNewConnectionAndReturnPendingPromiseWhenConnectionIsPending()
    {
        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(new Promise(function () { }));

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $mysql = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($mysql, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($mysql, $factory);

        $promise = $mysql->query('SELECT 1');

        $promise->then($this->expectCallableNever(), $this->expectCallableNever());
    }

    public function testQueryWillCreateNewConnectionAndReturnPendingPromiseWhenConnectionResolvesAndQueryOnConnectionIsPending()
    {
        $connection = $this->getMockBuilder('React\Mysql\Io\Connection')->disableOriginalConstructor()->getMock();
        $connection->expects($this->once())->method('query')->with('SELECT 1')->willReturn(new Promise(function () { }));

        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($connection));
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $mysql = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($mysql, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($mysql, $factory);

        $promise = $mysql->query('SELECT 1');

        $promise->then($this->expectCallableNever(), $this->expectCallableNever());
    }

    public function testQueryWillReturnResolvedPromiseWhenQueryOnConnectionResolves()
    {
        $result = new MysqlResult();
        $connection = $this->getMockBuilder('React\Mysql\Io\Connection')->disableOriginalConstructor()->getMock();
        $connection->expects($this->once())->method('query')->with('SELECT 1')->willReturn(\React\Promise\resolve($result));

        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($connection));
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $mysql = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($mysql, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($mysql, $factory);

        $promise = $mysql->query('SELECT 1');

        $promise->then($this->expectCallableOnceWith($result));
    }

    public function testQueryWillReturnRejectedPromiseWhenCreateConnectionRejects()
    {
        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\reject(new \RuntimeException()));
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $mysql = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($mysql, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($mysql, $factory);

        $promise = $mysql->query('SELECT 1');

        $promise->then(null, $this->expectCallableOnce());
    }

    public function testQueryWillReturnRejectedPromiseWhenQueryOnConnectionRejectsAfterCreateConnectionResolves()
    {
        $connection = $this->getMockBuilder('React\Mysql\Io\Connection')->disableOriginalConstructor()->getMock();
        $connection->expects($this->once())->method('query')->with('SELECT 1')->willReturn(\React\Promise\reject(new \RuntimeException()));

        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($connection));
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $mysql = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($mysql, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($mysql, $factory);

        $promise = $mysql->query('SELECT 1');

        $promise->then(null, $this->expectCallableOnce());
    }

    public function testQueryTwiceWillCreateSingleConnectionAndReturnPendingPromiseWhenCreateConnectionIsPending()
    {
        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(new Promise(function () { }));

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $mysql = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($mysql, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($mysql, $factory);

        $mysql->query('SELECT 1');

        $promise = $mysql->query('SELECT 2');

        $promise->then($this->expectCallableNever(), $this->expectCallableNever());
    }

    public function testQueryTwiceWillCallQueryOnConnectionOnlyOnceWhenQueryIsStillPending()
    {
        $connection = $this->getMockBuilder('React\Mysql\Io\Connection')->disableOriginalConstructor()->getMock();
        $connection->expects($this->once())->method('query')->with('SELECT 1')->willReturn(new Promise(function () { }));
        $connection->expects($this->once())->method('isBusy')->willReturn(true);

        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($connection));
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $mysql = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($mysql, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($mysql, $factory);

        $mysql->query('SELECT 1');

        $promise = $mysql->query('SELECT 2');

        $promise->then($this->expectCallableNever(), $this->expectCallableNever());
    }

    public function testQueryTwiceWillReuseConnectionForSecondQueryWhenFirstQueryIsAlreadyResolved()
    {
        $connection = $this->getMockBuilder('React\Mysql\Io\Connection')->disableOriginalConstructor()->getMock();
        $connection->expects($this->exactly(2))->method('query')->withConsecutive(
            ['SELECT 1'],
            ['SELECT 2']
        )->willReturnOnConsecutiveCalls(
            \React\Promise\resolve(new MysqlResult()),
            new Promise(function () { })
        );
        $connection->expects($this->once())->method('isBusy')->willReturn(false);

        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($connection));
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $mysql = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($mysql, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($mysql, $factory);

        $mysql->query('SELECT 1');

        $promise = $mysql->query('SELECT 2');

        $promise->then($this->expectCallableNever(), $this->expectCallableNever());
    }

    public function testQueryTwiceWillCallSecondQueryOnConnectionAfterFirstQueryResolvesWhenBothQueriesAreGivenBeforeCreateConnectionResolves()
    {
        $connection = $this->getMockBuilder('React\Mysql\Io\Connection')->disableOriginalConstructor()->getMock();
        $connection->expects($this->exactly(2))->method('query')->withConsecutive(
            ['SELECT 1'],
            ['SELECT 2']
        )->willReturnOnConsecutiveCalls(
            \React\Promise\resolve(new MysqlResult()),
            new Promise(function () { })
        );
        $connection->expects($this->never())->method('isBusy');

        $deferred = new Deferred();
        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn($deferred->promise());
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $mysql = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($mysql, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($mysql, $factory);

        $mysql->query('SELECT 1');

        $promise = $mysql->query('SELECT 2');

        $deferred->resolve($connection);

        $promise->then($this->expectCallableNever(), $this->expectCallableNever());
    }

    public function testQueryTwiceWillCreateNewConnectionForSecondQueryWhenFirstConnectionIsClosedAfterFirstQueryIsResolved()
    {
        $connection = $this->getMockBuilder('React\Mysql\Io\Connection')->disableOriginalConstructor()->setMethods(['query', 'isBusy'])->getMock();
        $connection->expects($this->once())->method('query')->with('SELECT 1')->willReturn(\React\Promise\resolve(new MysqlResult()));
        $connection->expects($this->never())->method('isBusy');

        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->exactly(2))->method('createConnection')->willReturnOnConsecutiveCalls(
            \React\Promise\resolve($connection),
            new Promise(function () { })
        );
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $mysql = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($mysql, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($mysql, $factory);

        $mysql->query('SELECT 1');

        assert($connection instanceof Connection);
        $connection->emit('close');

        $promise = $mysql->query('SELECT 2');

        $promise->then($this->expectCallableNever(), $this->expectCallableNever());
    }

    public function testQueryTwiceWillCloseFirstConnectionAndCreateNewConnectionForSecondQueryWhenFirstConnectionIsInClosingStateDueToIdleTimerAfterFirstQueryIsResolved()
    {
        $connection = $this->getMockBuilder('React\Mysql\Io\Connection')->disableOriginalConstructor()->setMethods(['query', 'isBusy', 'close'])->getMock();
        $connection->expects($this->once())->method('query')->with('SELECT 1')->willReturn(\React\Promise\resolve(new MysqlResult()));
        $connection->expects($this->once())->method('close');
        $connection->expects($this->never())->method('isBusy');

        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->exactly(2))->method('createConnection')->willReturnOnConsecutiveCalls(
            \React\Promise\resolve($connection),
            new Promise(function () { })
        );
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $mysql = new MysqlClient('', null, $loop);

        $mysql->on('close', $this->expectCallableNever());

        $ref = new \ReflectionProperty($mysql, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($mysql, $factory);

        $mysql->query('SELECT 1');

        // emulate triggering idle timer by setting connection state to closing
        $connection->state = Connection::STATE_CLOSING;

        $promise = $mysql->query('SELECT 2');

        $promise->then($this->expectCallableNever(), $this->expectCallableNever());
    }

    public function testQueryTwiceWillRejectFirstQueryWhenCreateConnectionRejectsAndWillCreateNewConnectionForSecondQuery()
    {
        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->exactly(2))->method('createConnection')->willReturnOnConsecutiveCalls(
            \React\Promise\reject(new \RuntimeException()),
            new Promise(function () { })
        );
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $mysql = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($mysql, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($mysql, $factory);

        $promise1 = $mysql->query('SELECT 1');

        $promise2 = $mysql->query('SELECT 2');

        $promise1->then(null, $this->expectCallableOnce());

        $promise2->then($this->expectCallableNever(), $this->expectCallableNever());
    }

    public function testQueryTwiceWillRejectBothQueriesWhenBothQueriesAreGivenBeforeCreateConnectionRejects()
    {
        $deferred = new Deferred();
        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn($deferred->promise());
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $mysql = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($mysql, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($mysql, $factory);

        $promise1 = $mysql->query('SELECT 1');
        $promise2 = $mysql->query('SELECT 2');

        $deferred->reject(new \RuntimeException());

        $promise1->then(null, $this->expectCallableOnce());
        $promise2->then(null, $this->expectCallableOnce());
    }

    public function testQueryTriceWillRejectFirstTwoQueriesAndKeepThirdPendingWhenTwoQueriesAreGivenBeforeCreateConnectionRejects()
    {
        $deferred = new Deferred();
        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->exactly(2))->method('createConnection')->willReturnOnConsecutiveCalls(
            $deferred->promise(),
            new Promise(function () { })
        );
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $mysql = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($mysql, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($mysql, $factory);

        $promise1 = $mysql->query('SELECT 1');
        $promise2 = $mysql->query('SELECT 2');

        $promise3 = $promise1->then(null, function () use ($mysql) {
            return $mysql->query('SELECT 3');
        });

        $deferred->reject(new \RuntimeException());

        $promise1->then(null, $this->expectCallableOnce());
        $promise2->then(null, $this->expectCallableOnce());
        $promise3->then($this->expectCallableNever(), $this->expectCallableNever());
    }

    public function testQueryTwiceWillCallSecondQueryOnConnectionAfterFirstQueryRejectsWhenBothQueriesAreGivenBeforeCreateConnectionResolves()
    {
        $connection = $this->getMockBuilder('React\Mysql\Io\Connection')->disableOriginalConstructor()->getMock();
        $connection->expects($this->exactly(2))->method('query')->withConsecutive(
            ['SELECT 1'],
            ['SELECT 2']
        )->willReturnOnConsecutiveCalls(
            \React\Promise\reject(new \RuntimeException()),
            new Promise(function () { })
        );
        $connection->expects($this->never())->method('isBusy');

        $deferred = new Deferred();
        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn($deferred->promise());
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $mysql = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($mysql, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($mysql, $factory);

        $promise1 = $mysql->query('SELECT 1');

        $promise2 = $mysql->query('SELECT 2');

        $deferred->resolve($connection);

        $promise1->then(null, $this->expectCallableOnce());
        $promise2->then($this->expectCallableNever(), $this->expectCallableNever());
    }

    public function testQueryStreamWillCreateNewConnectionAndReturnReadableStreamWhenConnectionIsPending()
    {
        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(new Promise(function () { }));

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $mysql = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($mysql, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($mysql, $factory);

        $stream = $mysql->queryStream('SELECT 1');

        $this->assertTrue($stream->isReadable());
    }

    public function testQueryStreamWillCreateNewConnectionAndReturnReadableStreamWhenConnectionResolvesAndQueryStreamOnConnectionReturnsReadableStream()
    {
        $connection = $this->getMockBuilder('React\Mysql\Io\Connection')->disableOriginalConstructor()->getMock();
        $connection->expects($this->once())->method('queryStream')->with('SELECT 1')->willReturn(new ThroughStream());

        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($connection));
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $mysql = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($mysql, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($mysql, $factory);

        $stream = $mysql->queryStream('SELECT 1');

        $this->assertTrue($stream->isReadable());
    }

    public function testQueryStreamTwiceWillCallQueryStreamOnConnectionOnlyOnceWhenQueryStreamIsStillReadable()
    {
        $connection = $this->getMockBuilder('React\Mysql\Io\Connection')->disableOriginalConstructor()->getMock();
        $connection->expects($this->once())->method('queryStream')->with('SELECT 1')->willReturn(new ThroughStream());
        $connection->expects($this->once())->method('isBusy')->willReturn(true);

        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($connection));
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $mysql = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($mysql, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($mysql, $factory);

        $mysql->queryStream('SELECT 1');

        $stream = $mysql->queryStream('SELECT 2');

        $this->assertTrue($stream->isReadable());
    }

    public function testQueryStreamTwiceWillReuseConnectionForSecondQueryStreamWhenFirstQueryStreamEnds()
    {
        $connection = $this->getMockBuilder('React\Mysql\Io\Connection')->disableOriginalConstructor()->getMock();
        $connection->expects($this->exactly(2))->method('queryStream')->withConsecutive(
            ['SELECT 1'],
            ['SELECT 2']
        )->willReturnOnConsecutiveCalls(
            $base = new ThroughStream(),
            new ThroughStream()
        );
        $connection->expects($this->once())->method('isBusy')->willReturn(false);

        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($connection));
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $mysql = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($mysql, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($mysql, $factory);

        $mysql->queryStream('SELECT 1');

        $base->end();

        $stream = $mysql->queryStream('SELECT 2');

        $this->assertTrue($stream->isReadable());
    }

    public function testQueryStreamTwiceWillReuseConnectionForSecondQueryStreamWhenFirstQueryStreamEmitsError()
    {
        $connection = $this->getMockBuilder('React\Mysql\Io\Connection')->disableOriginalConstructor()->getMock();
        $connection->expects($this->exactly(2))->method('queryStream')->withConsecutive(
            ['SELECT 1'],
            ['SELECT 2']
        )->willReturnOnConsecutiveCalls(
            $base = new ThroughStream(),
            new ThroughStream()
        );
        $connection->expects($this->once())->method('isBusy')->willReturn(true);

        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($connection));
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $mysql = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($mysql, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($mysql, $factory);

        $stream1 = $mysql->queryStream('SELECT 1');
        $stream2 = $mysql->queryStream('SELECT 2');

        $this->assertTrue($stream1->isReadable());
        $this->assertTrue($stream2->isReadable());

        $base->emit('error', [new \RuntimeException()]);

        $this->assertFalse($stream1->isReadable());
        $this->assertTrue($stream2->isReadable());
    }

    public function testQueryStreamTwiceWillWaitForFirstQueryStreamToEndBeforeStartingSecondQueryStreamWhenFirstQueryStreamIsExplicitlyClosed()
    {
        $connection = $this->getMockBuilder('React\Mysql\Io\Connection')->disableOriginalConstructor()->getMock();
        $connection->expects($this->once())->method('queryStream')->with('SELECT 1')->willReturn(new ThroughStream());
        $connection->expects($this->once())->method('isBusy')->willReturn(true);

        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($connection));
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $mysql = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($mysql, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($mysql, $factory);

        $stream1 = $mysql->queryStream('SELECT 1');
        $stream2 = $mysql->queryStream('SELECT 2');

        $this->assertTrue($stream1->isReadable());
        $this->assertTrue($stream2->isReadable());

        $stream1->close();

        $this->assertFalse($stream1->isReadable());
        $this->assertTrue($stream2->isReadable());
    }

    public function testQueryStreamTwiceWillCallSecondQueryStreamOnConnectionAfterFirstQueryStreamIsClosedWhenBothQueriesAreGivenBeforeCreateConnectionResolves()
    {
        $connection = $this->getMockBuilder('React\Mysql\Io\Connection')->disableOriginalConstructor()->getMock();
        $connection->expects($this->exactly(2))->method('queryStream')->withConsecutive(
            ['SELECT 1'],
            ['SELECT 2']
        )->willReturnOnConsecutiveCalls(
            $base = new ThroughStream(),
            new ThroughStream()
        );
        $connection->expects($this->never())->method('isBusy');

        $deferred = new Deferred();
        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn($deferred->promise());
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $mysql = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($mysql, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($mysql, $factory);

        $mysql->queryStream('SELECT 1');

        $stream = $mysql->queryStream('SELECT 2');

        $deferred->resolve($connection);
        $base->end();

        $this->assertTrue($stream->isReadable());
    }

    public function testQueryStreamTwiceWillCreateNewConnectionForSecondQueryStreamWhenFirstConnectionIsClosedAfterFirstQueryStreamIsClosed()
    {
        $connection = $this->getMockBuilder('React\Mysql\Io\Connection')->disableOriginalConstructor()->setMethods(['queryStream', 'isBusy'])->getMock();
        $connection->expects($this->once())->method('queryStream')->with('SELECT 1')->willReturn($base = new ThroughStream());
        $connection->expects($this->never())->method('isBusy');

        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->exactly(2))->method('createConnection')->willReturnOnConsecutiveCalls(
            \React\Promise\resolve($connection),
            new Promise(function () { })
        );
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $mysql = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($mysql, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($mysql, $factory);

        $mysql->queryStream('SELECT 1');

        $base->end();
        assert($connection instanceof Connection);
        $connection->emit('close');

        $stream = $mysql->queryStream('SELECT 2');

        $this->assertTrue($stream->isReadable());
    }

    public function testQueryStreamTwiceWillCloseFirstConnectionAndCreateNewConnectionForSecondQueryStreamWhenFirstConnectionIsInClosingStateDueToIdleTimerAfterFirstQueryStreamIsClosed()
    {
        $connection = $this->getMockBuilder('React\Mysql\Io\Connection')->disableOriginalConstructor()->setMethods(['queryStream', 'isBusy', 'close'])->getMock();
        $connection->expects($this->once())->method('queryStream')->with('SELECT 1')->willReturn($base = new ThroughStream());
        $connection->expects($this->once())->method('close');
        $connection->expects($this->never())->method('isBusy');

        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->exactly(2))->method('createConnection')->willReturnOnConsecutiveCalls(
            \React\Promise\resolve($connection),
            new Promise(function () { })
        );
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $mysql = new MysqlClient('', null, $loop);

        $mysql->on('close', $this->expectCallableNever());

        $ref = new \ReflectionProperty($mysql, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($mysql, $factory);

        $mysql->queryStream('SELECT 1');

        $base->end();
        // emulate triggering idle timer by setting connection state to closing
        $connection->state = Connection::STATE_CLOSING;

        $stream = $mysql->queryStream('SELECT 2');

        $this->assertTrue($stream->isReadable());
    }

    public function testQueryStreamTwiceWillEmitErrorOnFirstQueryStreamWhenCreateConnectionRejectsAndWillCreateNewConnectionForSecondQueryStream()
    {
        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->exactly(2))->method('createConnection')->willReturnOnConsecutiveCalls(
            \React\Promise\reject(new \RuntimeException()),
            new Promise(function () { })
        );
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $mysql = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($mysql, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($mysql, $factory);

        $stream1 = $mysql->queryStream('SELECT 1');

        $this->assertFalse($stream1->isReadable());

        $stream2 = $mysql->queryStream('SELECT 2');

        $this->assertTrue($stream2->isReadable());
    }

    public function testQueryStreamTwiceWillEmitErrorOnBothQueriesWhenBothQueriesAreGivenBeforeCreateConnectionRejects()
    {
        $deferred = new Deferred();
        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn($deferred->promise());
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $mysql = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($mysql, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($mysql, $factory);

        $stream1 = $mysql->queryStream('SELECT 1');
        $stream2 = $mysql->queryStream('SELECT 2');

        $stream1->on('error', $this->expectCallableOnceWith($this->isInstanceOf('Exception')));
        $stream1->on('close', $this->expectCallableOnce());

        $stream2->on('error', $this->expectCallableOnceWith($this->isInstanceOf('Exception')));
        $stream2->on('close', $this->expectCallableOnce());

        $deferred->reject(new \RuntimeException());

        $this->assertFalse($stream1->isReadable());
        $this->assertFalse($stream2->isReadable());
    }

    public function testPingWillCreateNewConnectionAndReturnPendingPromiseWhenConnectionIsPending()
    {
        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(new Promise(function () { }));

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $mysql = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($mysql, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($mysql, $factory);

        $promise = $mysql->ping();

        $promise->then($this->expectCallableNever(), $this->expectCallableNever());
    }

    public function testPingWillCreateNewConnectionAndReturnPendingPromiseWhenConnectionResolvesAndPingOnConnectionIsPending()
    {
        $connection = $this->getMockBuilder('React\Mysql\Io\Connection')->disableOriginalConstructor()->getMock();
        $connection->expects($this->once())->method('ping')->willReturn(new Promise(function () { }));

        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($connection));
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $mysql = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($mysql, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($mysql, $factory);

        $promise = $mysql->ping();

        $promise->then($this->expectCallableNever(), $this->expectCallableNever());
    }

    public function testPingWillReturnResolvedPromiseWhenPingOnConnectionResolves()
    {
        $connection = $this->getMockBuilder('React\Mysql\Io\Connection')->disableOriginalConstructor()->getMock();
        $connection->expects($this->once())->method('ping')->willReturn(\React\Promise\resolve(null));

        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($connection));
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $mysql = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($mysql, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($mysql, $factory);

        $promise = $mysql->ping();

        $promise->then($this->expectCallableOnce());
    }

    public function testPingWillReturnRejectedPromiseWhenCreateConnectionRejects()
    {
        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\reject(new \RuntimeException()));
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $mysql = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($mysql, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($mysql, $factory);

        $promise = $mysql->ping();

        $promise->then(null, $this->expectCallableOnce());
    }

    public function testPingWillReturnRejectedPromiseWhenPingOnConnectionRejectsAfterCreateConnectionResolves()
    {
        $connection = $this->getMockBuilder('React\Mysql\Io\Connection')->disableOriginalConstructor()->getMock();
        $connection->expects($this->once())->method('ping')->willReturn(\React\Promise\reject(new \RuntimeException()));

        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($connection));
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $mysql = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($mysql, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($mysql, $factory);

        $promise = $mysql->ping();

        $promise->then(null, $this->expectCallableOnce());
    }

    public function testPingTwiceWillCreateSingleConnectionAndReturnPendingPromiseWhenCreateConnectionIsPending()
    {
        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(new Promise(function () { }));

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $mysql = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($mysql, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($mysql, $factory);

        $mysql->ping();

        $promise = $mysql->ping();

        $promise->then($this->expectCallableNever(), $this->expectCallableNever());
    }

    public function testPingTwiceWillCallPingOnConnectionOnlyOnceWhenPingIsStillPending()
    {
        $connection = $this->getMockBuilder('React\Mysql\Io\Connection')->disableOriginalConstructor()->getMock();
        $connection->expects($this->once())->method('ping')->willReturn(new Promise(function () { }));
        $connection->expects($this->once())->method('isBusy')->willReturn(true);

        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($connection));
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $mysql = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($mysql, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($mysql, $factory);

        $mysql->ping();

        $promise = $mysql->ping();

        $promise->then($this->expectCallableNever(), $this->expectCallableNever());
    }

    public function testPingTwiceWillReuseConnectionForSecondPingWhenFirstPingIsAlreadyResolved()
    {
        $connection = $this->getMockBuilder('React\Mysql\Io\Connection')->disableOriginalConstructor()->getMock();
        $connection->expects($this->exactly(2))->method('ping')->willReturnOnConsecutiveCalls(
            \React\Promise\resolve(null),
            new Promise(function () { })
        );
        $connection->expects($this->once())->method('isBusy')->willReturn(false);

        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($connection));
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $mysql = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($mysql, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($mysql, $factory);

        $mysql->ping();

        $promise = $mysql->ping();

        $promise->then($this->expectCallableNever(), $this->expectCallableNever());
    }

    public function testPingTwiceWillCallSecondPingOnConnectionAfterFirstPingResolvesWhenBothQueriesAreGivenBeforeCreateConnectionResolves()
    {
        $connection = $this->getMockBuilder('React\Mysql\Io\Connection')->disableOriginalConstructor()->getMock();
        $connection->expects($this->exactly(2))->method('ping')->willReturnOnConsecutiveCalls(
            \React\Promise\resolve(new MysqlResult()),
            new Promise(function () { })
        );
        $connection->expects($this->never())->method('isBusy');

        $deferred = new Deferred();
        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn($deferred->promise());
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $mysql = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($mysql, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($mysql, $factory);

        $mysql->ping();

        $promise = $mysql->ping();

        $deferred->resolve($connection);

        $promise->then($this->expectCallableNever(), $this->expectCallableNever());
    }

    public function testPingTwiceWillCreateNewConnectionForSecondPingWhenFirstConnectionIsClosedAfterFirstPingIsResolved()
    {
        $connection = $this->getMockBuilder('React\Mysql\Io\Connection')->disableOriginalConstructor()->setMethods(['ping', 'isBusy'])->getMock();
        $connection->expects($this->once())->method('ping')->willReturn(\React\Promise\resolve(null));
        $connection->expects($this->never())->method('isBusy');

        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->exactly(2))->method('createConnection')->willReturnOnConsecutiveCalls(
            \React\Promise\resolve($connection),
            new Promise(function () { })
        );
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $mysql = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($mysql, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($mysql, $factory);

        $mysql->ping();

        assert($connection instanceof Connection);
        $connection->emit('close');

        $promise = $mysql->ping();

        $promise->then($this->expectCallableNever(), $this->expectCallableNever());
    }

    public function testPingTwiceWillCloseFirstConnectionAndCreateNewConnectionForSecondPingWhenFirstConnectionIsInClosingStateDueToIdleTimerAfterFirstPingIsResolved()
    {
        $connection = $this->getMockBuilder('React\Mysql\Io\Connection')->disableOriginalConstructor()->setMethods(['ping', 'isBusy', 'close'])->getMock();
        $connection->expects($this->once())->method('ping')->willReturn(\React\Promise\resolve(null));
        $connection->expects($this->once())->method('close');
        $connection->expects($this->never())->method('isBusy');

        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->exactly(2))->method('createConnection')->willReturnOnConsecutiveCalls(
            \React\Promise\resolve($connection),
            new Promise(function () { })
        );
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $mysql = new MysqlClient('', null, $loop);

        $mysql->on('close', $this->expectCallableNever());

        $ref = new \ReflectionProperty($mysql, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($mysql, $factory);

        $mysql->ping();

        // emulate triggering idle timer by setting connection state to closing
        $connection->state = Connection::STATE_CLOSING;

        $promise = $mysql->ping();

        $promise->then($this->expectCallableNever(), $this->expectCallableNever());
    }

    public function testPingTwiceWillRejectFirstPingWhenCreateConnectionRejectsAndWillCreateNewConnectionForSecondPing()
    {
        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->exactly(2))->method('createConnection')->willReturnOnConsecutiveCalls(
            \React\Promise\reject(new \RuntimeException()),
            new Promise(function () { })
        );
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $mysql = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($mysql, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($mysql, $factory);

        $promise1 = $mysql->ping();

        $promise2 = $mysql->ping();

        $promise1->then(null, $this->expectCallableOnce());

        $promise2->then($this->expectCallableNever(), $this->expectCallableNever());
    }

    public function testPingTwiceWillRejectBothQueriesWhenBothQueriesAreGivenBeforeCreateConnectionRejects()
    {
        $deferred = new Deferred();
        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn($deferred->promise());
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $mysql = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($mysql, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($mysql, $factory);

        $promise1 = $mysql->ping();
        $promise2 = $mysql->ping();

        $deferred->reject(new \RuntimeException());

        $promise1->then(null, $this->expectCallableOnce());
        $promise2->then(null, $this->expectCallableOnce());
    }

    public function testPingTriceWillRejectFirstTwoQueriesAndKeepThirdPendingWhenTwoQueriesAreGivenBeforeCreateConnectionRejects()
    {
        $deferred = new Deferred();
        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->exactly(2))->method('createConnection')->willReturnOnConsecutiveCalls(
            $deferred->promise(),
            new Promise(function () { })
        );
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $mysql = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($mysql, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($mysql, $factory);

        $promise1 = $mysql->ping();
        $promise2 = $mysql->ping();

        $promise3 = $promise1->then(null, function () use ($mysql) {
            return $mysql->ping();
        });

        $deferred->reject(new \RuntimeException());

        $promise1->then(null, $this->expectCallableOnce());
        $promise2->then(null, $this->expectCallableOnce());
        $promise3->then($this->expectCallableNever(), $this->expectCallableNever());
    }

    public function testPingTwiceWillCallSecondPingOnConnectionAfterFirstPingRejectsWhenBothQueriesAreGivenBeforeCreateConnectionResolves()
    {
        $connection = $this->getMockBuilder('React\Mysql\Io\Connection')->disableOriginalConstructor()->getMock();
        $connection->expects($this->exactly(2))->method('ping')->willReturnOnConsecutiveCalls(
            \React\Promise\reject(new \RuntimeException()),
            new Promise(function () { })
        );
        $connection->expects($this->never())->method('isBusy');

        $deferred = new Deferred();
        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn($deferred->promise());
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $mysql = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($mysql, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($mysql, $factory);

        $promise1 = $mysql->ping();

        $promise2 = $mysql->ping();

        $deferred->resolve($connection);

        $promise1->then(null, $this->expectCallableOnce());
        $promise2->then($this->expectCallableNever(), $this->expectCallableNever());
    }

    public function testQueryWillResolveWhenQueryFromUnderlyingConnectionResolves()
    {
        $result = new MysqlResult();

        $base = $this->getMockBuilder('React\Mysql\Io\Connection')->disableOriginalConstructor()->getMock();
        $base->expects($this->once())->method('query')->with('SELECT 1')->willReturn(\React\Promise\resolve($result));

        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($base));

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $connection = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($connection, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($connection, $factory);

        $ret = $connection->query('SELECT 1');
        $ret->then($this->expectCallableOnceWith($result), $this->expectCallableNever());
    }

    public function testPingAfterQueryWillPassPingToConnectionWhenQueryResolves()
    {
        $result = new MysqlResult();
        $deferred = new Deferred();

        $base = $this->getMockBuilder('React\Mysql\Io\Connection')->disableOriginalConstructor()->getMock();
        $base->expects($this->once())->method('query')->with('SELECT 1')->willReturn($deferred->promise());
        $base->expects($this->once())->method('ping')->willReturn(new Promise(function () { }));

        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($base));

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $connection = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($connection, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($connection, $factory);

        $ret = $connection->query('SELECT 1');
        $connection->ping();

        $deferred->resolve($result);

        $ret->then($this->expectCallableOnceWith($result), $this->expectCallableNever());
    }

    public function testQueryWillRejectWhenQueryFromUnderlyingConnectionRejects()
    {
        $error = new \RuntimeException();

        $base = $this->getMockBuilder('React\Mysql\Io\Connection')->disableOriginalConstructor()->getMock();
        $base->expects($this->once())->method('query')->with('SELECT 1')->willReturn(\React\Promise\reject($error));

        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($base));

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $connection = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($connection, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($connection, $factory);

        $ret = $connection->query('SELECT 1');
        $ret->then($this->expectCallableNever(), $this->expectCallableOnceWith($error));
    }

    public function testQueryWillRejectWhenUnderlyingConnectionRejects()
    {
        $deferred = new Deferred();
        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn($deferred->promise());

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $connection = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($connection, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($connection, $factory);

        $ret = $connection->query('SELECT 1');
        $ret->then($this->expectCallableNever(), $this->expectCallableOnce());

        $deferred->reject(new \RuntimeException());
    }

    public function testQueryStreamReturnsReadableStreamWhenConnectionIsPending()
    {
        $promise = new Promise(function () { });
        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn($promise);
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $connection = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($connection, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($connection, $factory);

        $ret = $connection->queryStream('SELECT 1');

        $this->assertTrue($ret instanceof ReadableStreamInterface);
        $this->assertTrue($ret->isReadable());
    }

    public function testQueryStreamWillReturnStreamFromUnderlyingConnectionWhenResolved()
    {
        $stream = new ThroughStream();
        $base = $this->getMockBuilder('React\Mysql\Io\Connection')->disableOriginalConstructor()->getMock();
        $base->expects($this->once())->method('queryStream')->with('SELECT 1')->willReturn($stream);

        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($base));

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $connection = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($connection, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($connection, $factory);

        $ret = $connection->queryStream('SELECT 1');

        $ret->on('data', $this->expectCallableOnceWith('hello'));
        $stream->write('hello');

        $this->assertTrue($ret->isReadable());
    }

    public function testQueryStreamWillReturnStreamFromUnderlyingConnectionWhenResolvedAndClosed()
    {
        $stream = new ThroughStream();
        $base = $this->getMockBuilder('React\Mysql\Io\Connection')->disableOriginalConstructor()->getMock();
        $base->expects($this->once())->method('queryStream')->with('SELECT 1')->willReturn($stream);

        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($base));

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $connection = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($connection, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($connection, $factory);

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
        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn($deferred->promise());
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $connection = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($connection, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($connection, $factory);

        $ret = $connection->queryStream('SELECT 1');

        $ret->on('error', $this->expectCallableOnce());
        $ret->on('close', $this->expectCallableOnce());

        $deferred->reject(new \RuntimeException());

        $this->assertFalse($ret->isReadable());
    }

    public function testPingReturnsPendingPromiseWhenConnectionIsPending()
    {
        $promise = new Promise(function () { });
        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn($promise);
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $connection = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($connection, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($connection, $factory);

        $ret = $connection->ping();

        $this->assertTrue($ret instanceof PromiseInterface);
        $ret->then($this->expectCallableNever(), $this->expectCallableNever());
    }

    public function testPingWillPingUnderlyingConnectionWhenResolved()
    {
        $base = $this->getMockBuilder('React\Mysql\Io\Connection')->disableOriginalConstructor()->getMock();
        $base->expects($this->once())->method('ping')->willReturn(new Promise(function () { }));

        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($base));
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $connection = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($connection, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($connection, $factory);

        $connection->ping();
    }

    public function testPingTwiceWillBothRejectWithSameErrorWhenUnderlyingConnectionRejects()
    {
        $error = new \RuntimeException();
        $deferred = new Deferred();

        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn($deferred->promise());
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $connection = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($connection, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($connection, $factory);

        $connection->ping()->then($this->expectCallableNever(), $this->expectCallableOnceWith($error));
        $connection->ping()->then($this->expectCallableNever(), $this->expectCallableOnceWith($error));

        $deferred->reject($error);
    }

    public function testPingWillTryToCreateNewUnderlyingConnectionAfterPreviousPingFailedToCreateUnderlyingConnection()
    {
        $error = new \RuntimeException();

        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->exactly(2))->method('createConnection')->willReturn(\React\Promise\reject($error));
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $connection = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($connection, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($connection, $factory);

        $connection->ping()->then($this->expectCallableNever(), $this->expectCallableOnceWith($error));
        $connection->ping()->then($this->expectCallableNever(), $this->expectCallableOnceWith($error));
    }

    public function testPingWillResolveWhenPingFromUnderlyingConnectionResolves()
    {
        $base = $this->getMockBuilder('React\Mysql\Io\Connection')->disableOriginalConstructor()->getMock();
        $base->expects($this->once())->method('ping')->willReturn(\React\Promise\resolve(null));

        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($base));

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $connection = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($connection, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($connection, $factory);

        $ret = $connection->ping();
        $ret->then($this->expectCallableOnce(), $this->expectCallableNever());
    }

    public function testPingWillRejectWhenPingFromUnderlyingConnectionRejects()
    {
        $error = new \RuntimeException();

        $base = $this->getMockBuilder('React\Mysql\Io\Connection')->disableOriginalConstructor()->getMock();
        $base->expects($this->once())->method('ping')->willReturn(\React\Promise\reject($error));

        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($base));

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $connection = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($connection, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($connection, $factory);

        $ret = $connection->ping();
        $ret->then($this->expectCallableNever(), $this->expectCallableOnceWith($error));
    }

    public function testPingWillRejectWhenPingFromUnderlyingConnectionEmitsCloseEventAndRejects()
    {
        $error = new \RuntimeException();

        $base = $this->getMockBuilder('React\Mysql\Io\Connection')->setMethods(['ping', 'close'])->disableOriginalConstructor()->getMock();
        $base->expects($this->once())->method('ping')->willReturnCallback(function () use ($base, $error) {
            $base->emit('close');
            return \React\Promise\reject($error);
        });
        $base->expects($this->never())->method('close');

        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($base));

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $connection = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($connection, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($connection, $factory);

        $ret = $connection->ping();
        $ret->then($this->expectCallableNever(), $this->expectCallableOnceWith($error));
    }

    public function testQuitResolvesAndEmitsCloseImmediatelyWhenConnectionIsNotAlreadyPending()
    {
        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->never())->method('createConnection');
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $connection = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($connection, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($connection, $factory);

        $connection->on('error', $this->expectCallableNever());
        $connection->on('close', $this->expectCallableOnce());

        $ret = $connection->quit();

        $this->assertTrue($ret instanceof PromiseInterface);
        $ret->then($this->expectCallableOnce(), $this->expectCallableNever());
    }

    public function testQuitAfterPingReturnsPendingPromiseWhenConnectionIsPending()
    {
        $promise = new Promise(function () { });
        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn($promise);
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $connection = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($connection, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($connection, $factory);

        $connection->ping();
        $ret = $connection->quit();

        $this->assertTrue($ret instanceof PromiseInterface);
        $ret->then($this->expectCallableNever(), $this->expectCallableNever());
    }

    public function testQuitAfterPingRejectsAndThenEmitsCloseWhenFactoryFailsToCreateUnderlyingConnection()
    {
        $deferred = new Deferred();
        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn($deferred->promise());
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $connection = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($connection, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($connection, $factory);

        $connection->ping()->then(null, $this->expectCallableOnce());

        $this->expectOutputString('reject.close.');
        $connection->on('close', function () {
            echo 'close.';
        });
        $connection->quit()->then(null, function () {
            echo 'reject.';
        });

        $deferred->reject(new \RuntimeException());
    }

    public function testQuitAfterPingWillQuitUnderlyingConnectionWhenResolved()
    {
        $base = $this->getMockBuilder('React\Mysql\Io\Connection')->disableOriginalConstructor()->getMock();
        $base->expects($this->once())->method('ping')->willReturn(\React\Promise\resolve(null));
        $base->expects($this->once())->method('quit')->willReturn(new Promise(function () { }));

        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($base));
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $connection = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($connection, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($connection, $factory);

        $connection->ping();
        $connection->quit();
    }

    public function testQuitAfterPingResolvesAndThenEmitsCloseWhenUnderlyingConnectionQuits()
    {
        $base = $this->getMockBuilder('React\Mysql\Io\Connection')->disableOriginalConstructor()->getMock();
        $deferred = new Deferred();
        $base->expects($this->once())->method('ping')->willReturn(\React\Promise\resolve(null));
        $base->expects($this->once())->method('quit')->willReturn($deferred->promise());

        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($base));
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $connection = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($connection, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($connection, $factory);

        $connection->ping();

        $this->expectOutputString('quit.close.');
        $connection->on('close', function () {
            echo 'close.';
        });
        $connection->quit()->then(function () {
            echo 'quit.';
        });

        $deferred->resolve(null);
    }

    public function testQuitAfterPingRejectsAndThenEmitsCloseWhenUnderlyingConnectionFailsToQuit()
    {
        $deferred = new Deferred();
        $base = $this->getMockBuilder('React\Mysql\Io\Connection')->disableOriginalConstructor()->getMock();
        $base->expects($this->once())->method('ping')->willReturn(\React\Promise\resolve(null));
        $base->expects($this->once())->method('quit')->willReturn($deferred->promise());

        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($base));
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $connection = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($connection, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($connection, $factory);

        $connection->ping();

        $this->expectOutputString('reject.close.');
        $connection->on('close', function () {
            echo 'close.';
        });
        $connection->quit()->then(null, function () {
            echo 'reject.';
        });

        $deferred->reject(new \RuntimeException());
    }

    public function testPingAfterQuitWillNotPassPingCommandToConnection()
    {
        $connection = $this->getMockBuilder('React\Mysql\Io\Connection')->setMethods(['ping', 'quit', 'close', 'isBusy'])->disableOriginalConstructor()->getMock();
        $connection->expects($this->once())->method('ping')->willReturn(\React\Promise\resolve(null));
        $connection->expects($this->once())->method('quit')->willReturn(new Promise(function () { }));
        $connection->expects($this->never())->method('close');
        $connection->expects($this->once())->method('isBusy')->willReturn(false);

        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($connection));

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $mysql = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($mysql, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($mysql, $factory);

        $mysql->on('close', $this->expectCallableNever());

        $mysql->ping();

        $mysql->quit();

        $mysql->ping()->then(null, $this->expectCallableOnce());
    }

    public function testCloseEmitsCloseImmediatelyWhenConnectionIsNotAlreadyPending()
    {
        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->never())->method('createConnection');
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $connection = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($connection, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($connection, $factory);

        $connection->on('error', $this->expectCallableNever());
        $connection->on('close', $this->expectCallableOnce());

        $connection->close();
    }

    public function testCloseAfterPingCancelsPendingConnection()
    {
        $deferred = new Deferred($this->expectCallableOnce());
        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn($deferred->promise());
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $connection = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($connection, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($connection, $factory);

        $connection->ping()->then(null, $this->expectCallableOnce());
        $connection->close();
    }

    public function testCloseTwiceAfterPingWillCloseUnderlyingConnectionWhenResolved()
    {
        $base = $this->getMockBuilder('React\Mysql\Io\Connection')->disableOriginalConstructor()->getMock();
        $base->expects($this->once())->method('ping')->willReturn(\React\Promise\resolve(null));
        $base->expects($this->once())->method('close');

        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($base));
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $connection = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($connection, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($connection, $factory);

        $connection->ping();
        $connection->close();
        $connection->close();
    }

    public function testCloseAfterPingDoesNotEmitConnectionErrorFromAbortedConnection()
    {
        $promise = new Promise(function () { }, function () {
            throw new \RuntimeException();
        });

        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn($promise);
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $connection = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($connection, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($connection, $factory);

        $connection->on('error', $this->expectCallableNever());
        $connection->on('close', $this->expectCallableOnce());

        $promise = $connection->ping();

        $promise->then(null, $this->expectCallableOnce()); // avoid reporting unhandled rejection

        $connection->close();
    }

    public function testCloseAfterPingWillCloseUnderlyingConnection()
    {
        $base = $this->getMockBuilder('React\Mysql\Io\Connection')->disableOriginalConstructor()->getMock();
        $base->expects($this->once())->method('ping')->willReturn(\React\Promise\resolve(null));
        $base->expects($this->once())->method('close');

        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($base));

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $connection = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($connection, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($connection, $factory);

        $connection->ping()->then($this->expectCallableOnce(), $this->expectCallableNever());
        $connection->close();
    }

    public function testCloseAfterPingHasResolvedWillCloseUnderlyingConnectionWithoutTryingToCancelConnection()
    {
        $base = $this->getMockBuilder('React\Mysql\Io\Connection')->setMethods(['ping', 'close'])->disableOriginalConstructor()->getMock();
        $base->expects($this->once())->method('ping')->willReturn(\React\Promise\resolve(null));
        $base->expects($this->once())->method('close');

        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($base));

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $connection = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($connection, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($connection, $factory);

        $connection->ping();
        $connection->close();
    }

    public function testCloseAfterQuitAfterPingWillCloseUnderlyingConnectionWhenQuitIsStillPending()
    {
        $base = $this->getMockBuilder('React\Mysql\Io\Connection')->disableOriginalConstructor()->getMock();
        $base->expects($this->once())->method('ping')->willReturn(\React\Promise\resolve(null));
        $base->expects($this->once())->method('quit')->willReturn(new Promise(function () { }));
        $base->expects($this->once())->method('close');

        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($base));
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $connection = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($connection, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($connection, $factory);

        $connection->ping();
        $connection->quit();
        $connection->close();
    }

    public function testCloseAfterConnectionIsInClosingStateDueToIdleTimerWillCloseUnderlyingConnection()
    {
        $base = $this->getMockBuilder('React\Mysql\Io\Connection')->disableOriginalConstructor()->getMock();
        $base->expects($this->once())->method('ping')->willReturn(\React\Promise\resolve(null));
        $base->expects($this->never())->method('quit');
        $base->expects($this->once())->method('close');

        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn(\React\Promise\resolve($base));

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $connection = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($connection, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($connection, $factory);

        $connection->ping();

        // emulate triggering idle timer by setting connection state to closing
        $base->state = Connection::STATE_CLOSING;

        $connection->close();
    }

    public function testCloseTwiceAfterPingEmitsCloseEventOnceWhenConnectionIsPending()
    {
        $promise = new Promise(function () { });
        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->once())->method('createConnection')->willReturn($promise);
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $connection = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($connection, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($connection, $factory);

        $connection->on('error', $this->expectCallableNever());
        $connection->on('close', $this->expectCallableOnce());

        $connection->ping()->then(null, $this->expectCallableOnce());
        $connection->close();
        $connection->close();
    }

    public function testQueryReturnsRejectedPromiseAfterConnectionIsClosed()
    {
        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->never())->method('createConnection');
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $connection = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($connection, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($connection, $factory);

        $connection->close();
        $ret = $connection->query('SELECT 1');

        $this->assertTrue($ret instanceof PromiseInterface);
        $ret->then($this->expectCallableNever(), $this->expectCallableOnce());
    }

    public function testQueryStreamThrowsAfterConnectionIsClosed()
    {
        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->never())->method('createConnection');
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $connection = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($connection, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($connection, $factory);

        $connection->close();

        $this->setExpectedException('React\Mysql\Exception');
        $connection->queryStream('SELECT 1');
    }

    public function testPingReturnsRejectedPromiseAfterConnectionIsClosed()
    {
        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->never())->method('createConnection');
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $connection = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($connection, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($connection, $factory);

        $connection->close();
        $ret = $connection->ping();

        $this->assertTrue($ret instanceof PromiseInterface);
        $ret->then($this->expectCallableNever(), $this->expectCallableOnce());
    }

    public function testQuitReturnsRejectedPromiseAfterConnectionIsClosed()
    {
        $factory = $this->getMockBuilder('React\Mysql\Io\Factory')->disableOriginalConstructor()->getMock();
        $factory->expects($this->never())->method('createConnection');
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $connection = new MysqlClient('', null, $loop);

        $ref = new \ReflectionProperty($connection, 'factory');
        $ref->setAccessible(true);
        $ref->setValue($connection, $factory);

        $connection->close();
        $ret = $connection->quit();

        $this->assertTrue($ret instanceof PromiseInterface);
        $ret->then($this->expectCallableNever(), $this->expectCallableOnce());
    }
}
