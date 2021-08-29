<?php

namespace React\Tests\MySQL\Io;

use React\MySQL\Io\Connection;
use React\Tests\MySQL\BaseTestCase;

class ConnectionTest extends BaseTestCase
{
    public function testQuitWillEnqueueOneCommand()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $executor = $this->getMockBuilder('React\MySQL\Io\Executor')->setMethods(['enqueue'])->getMock();
        $executor->expects($this->once())->method('enqueue')->willReturnArgument(0);

        $conn = new Connection($stream, $executor);
        $conn->quit();
    }

    public function testQueryAfterQuitRejectsImmediately()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $executor = $this->getMockBuilder('React\MySQL\Io\Executor')->setMethods(['enqueue'])->getMock();
        $executor->expects($this->once())->method('enqueue')->willReturnArgument(0);

        $conn = new Connection($stream, $executor);
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
        $executor = $this->getMockBuilder('React\MySQL\Io\Executor')->setMethods(['enqueue'])->getMock();
        $executor->expects($this->never())->method('enqueue');

        $conn = new Connection($stream, $executor);
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
        $executor = $this->getMockBuilder('React\MySQL\Io\Executor')->setMethods(['enqueue'])->getMock();
        $executor->expects($this->once())->method('enqueue')->willReturnArgument(0);

        $conn = new Connection($stream, $executor);
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
        $executor = $this->getMockBuilder('React\MySQL\Io\Executor')->setMethods(['enqueue'])->getMock();
        $executor->expects($this->once())->method('enqueue')->willReturnArgument(0);

        $conn = new Connection($stream, $executor);
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
        $executor = $this->getMockBuilder('React\MySQL\Io\Executor')->setMethods(['enqueue'])->getMock();
        $executor->expects($this->once())->method('enqueue')->willReturnArgument(0);

        $conn = new Connection($stream, $executor);
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
        $executor = $this->getMockBuilder('React\MySQL\Io\Executor')->setMethods(['enqueue'])->getMock();
        $executor->expects($this->never())->method('enqueue');

        $conn = new Connection($stream, $executor);
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
