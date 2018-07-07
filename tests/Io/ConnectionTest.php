<?php

namespace React\Tests\MySQL\Io;

use React\MySQL\Io\Connection;
use React\Tests\MySQL\BaseTestCase;

class ConnectionTest extends BaseTestCase
{
    public function testQuitWillEnqueueOneCommand()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $executor = $this->getMockBuilder('React\MySQL\Io\Executor')->setMethods(array('enqueue'))->getMock();
        $executor->expects($this->once())->method('enqueue')->willReturnArgument(0);

        $conn = new Connection($stream, $executor);
        $conn->quit();
    }

    public function testQueryAfterQuitRejectsImmediately()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $executor = $this->getMockBuilder('React\MySQL\Io\Executor')->setMethods(array('enqueue'))->getMock();
        $executor->expects($this->once())->method('enqueue')->willReturnArgument(0);

        $conn = new Connection($stream, $executor);
        $conn->quit();
        $conn->query('SELECT 1')->then(null, $this->expectCallableOnce());
    }

    /**
     * @expectedException React\MySQL\Exception
     */
    public function testQueryStreamAfterQuitThrows()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $executor = $this->getMockBuilder('React\MySQL\Io\Executor')->setMethods(array('enqueue'))->getMock();
        $executor->expects($this->once())->method('enqueue')->willReturnArgument(0);

        $conn = new Connection($stream, $executor);
        $conn->quit();
        $conn->queryStream('SELECT 1');
    }

    public function testPingAfterQuitRejectsImmediately()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $executor = $this->getMockBuilder('React\MySQL\Io\Executor')->setMethods(array('enqueue'))->getMock();
        $executor->expects($this->once())->method('enqueue')->willReturnArgument(0);

        $conn = new Connection($stream, $executor);
        $conn->quit();
        $conn->ping()->then(null, $this->expectCallableOnce());
    }

    public function testQuitAfterQuitRejectsImmediately()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $executor = $this->getMockBuilder('React\MySQL\Io\Executor')->setMethods(array('enqueue'))->getMock();
        $executor->expects($this->once())->method('enqueue')->willReturnArgument(0);

        $conn = new Connection($stream, $executor);
        $conn->quit();
        $conn->quit()->then(null, $this->expectCallableOnce());
    }
}
