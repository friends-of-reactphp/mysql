<?php

namespace React\Tests\MySQL;

use React\MySQL\Connection;
use React\MySQL\Exception;

class ConnectionTest extends BaseTestCase
{

    public function testConnectWithInvalidPass()
    {
        $options = $this->getConnectionOptions();
        $loop = \React\EventLoop\Factory::create();
        $conn = new Connection($loop, array('passwd' => 'invalidpass') + $options);

        $conn->connect(function ($err, $conn) use ($loop, $options) {
            $this->assertEquals(sprintf(
                "Access denied for user '%s'@'%s' (using password: YES)",
                $options['user'],
                $options['host']
            ), $err->getMessage());
            $this->assertInstanceOf('React\MySQL\Connection', $conn);
            //$loop->stop();
        });
        $loop->run();
    }

    /**
     * @expectedException React\MySQL\Exception
     * @expectedExceptionMessage Connection not in idle state
     */
    public function testConnectTwiceThrowsExceptionForSecondCall()
    {
        $options = $this->getConnectionOptions();
        $loop = \React\EventLoop\Factory::create();
        $conn = new Connection($loop, $options);

        $conn->connect(function () { });
        $conn->connect(function () { });
    }

    /**
     * @expectedException React\MySQL\Exception
     * @expectedExceptionMessage Can't send command
     */
    public function testCloseWithoutConnectThrows()
    {
        $options = $this->getConnectionOptions();
        $loop = \React\EventLoop\Factory::create();
        $conn = new Connection($loop, $options);

        $conn->close(function () { });
    }

    /**
     * @expectedException React\MySQL\Exception
     * @expectedExceptionMessage Can't send command
     */
    public function testQueryWithoutConnectThrows()
    {
        $options = $this->getConnectionOptions();
        $loop = \React\EventLoop\Factory::create();
        $conn = new Connection($loop, $options);

        $conn->query('SELECT 1', function () { });
    }

    /**
     * @expectedException React\MySQL\Exception
     * @expectedExceptionMessage Can't send command
     */
    public function testPingWithoutConnectThrows()
    {
        $options = $this->getConnectionOptions();
        $loop = \React\EventLoop\Factory::create();
        $conn = new Connection($loop, $options);

        $conn->ping(function () { });
    }

    public function testCloseWhileConnectingWillBeQueuedAfterConnection()
    {
        $this->expectOutputString('connectedclosed');
        $options = $this->getConnectionOptions();
        $loop = \React\EventLoop\Factory::create();
        $conn = new Connection($loop, $options);

        $conn->connect(function ($err) {
            echo $err ? $err : 'connected';
        });
        $conn->close(function () {
            echo 'closed';
        });

        $loop->run();
    }

    public function testPingAndCloseWhileConnectingWillBeQueuedAfterConnection()
    {
        $this->expectOutputString('connectedpingclosed');
        $options = $this->getConnectionOptions();
        $loop = \React\EventLoop\Factory::create();
        $conn = new Connection($loop, $options);

        $conn->connect(function ($err) {
            echo $err ? $err : 'connected';
        });
        $conn->ping(function ($err) {
            echo $err ? $err : 'ping';
        });
        $conn->close(function () {
            echo 'closed';
        });

        $loop->run();
    }

    public function testPingAfterCloseWhileConnectingThrows()
    {
        $this->expectOutputString('connectedclosed');
        $options = $this->getConnectionOptions();
        $loop = \React\EventLoop\Factory::create();
        $conn = new Connection($loop, $options);

        $conn->connect(function ($err) {
            echo $err ? $err : 'connected';
        });
        $conn->close(function () {
            echo 'closed';
        });

        try {
            $conn->ping(function ($err) {
                echo $err ? $err : 'ping';
            });
            $this->fail();
        } catch (Exception $e) {
            // expected
        }

        $loop->run();
    }

    public function testCloseWhileConnectingWithInvalidPassWillNeverFire()
    {
        $this->expectOutputString('error');
        $options = $this->getConnectionOptions();
        $loop = \React\EventLoop\Factory::create();
        $conn = new Connection($loop, array('passwd' => 'invalidpass') + $options);

        $conn->connect(function ($err) {
            echo $err ? 'error' : 'connected';
        });
        $conn->close(function () {
            echo 'never';
        });

        $loop->run();
    }

    public function testConnectWithValidPass()
    {
        $this->expectOutputString('endclose');

        $loop = \React\EventLoop\Factory::create();
        $conn = new Connection($loop, $this->getConnectionOptions());

        $conn->on('end', function ($conn){
            $this->assertInstanceOf('React\MySQL\Connection', $conn);
            echo 'end';
        });

        $conn->on('close', function ($conn){
            $this->assertInstanceOf('React\MySQL\Connection', $conn);
            echo 'close';
        });

        $conn->connect(function ($err, $conn) use ($loop) {
            $this->assertEquals(null, $err);
            $this->assertInstanceOf('React\MySQL\Connection', $conn);
        });

        $conn->ping(function ($err, $conn) use ($loop) {
            $this->assertEquals(null, $err);
            $conn->close(function ($conn) {
                $this->assertEquals($conn::STATE_CLOSED, $conn->getState());
            });
            //$loop->stop();
        });
        $loop->run();
    }
}
