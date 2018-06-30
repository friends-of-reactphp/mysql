<?php

namespace React\Tests\MySQL;

use React\MySQL\Connection;
use React\Socket\Server;

class ConnectionTest extends BaseTestCase
{
    public function testConnectWithInvalidHostRejectsWithConnectionError()
    {
        $options = $this->getConnectionOptions();
        $loop = \React\EventLoop\Factory::create();
        $conn = new Connection($loop, array('host' => 'example.invalid') + $options);

        $conn->on('error', $this->expectCallableOnce());

        $conn->doConnect(function ($err, $conn) use ($loop, $options) {
            $this->assertInstanceOf('React\MySQL\Connection', $conn);
            $this->assertEquals(Connection::STATE_CONNECT_FAILED, $conn->getState());
        });
        $loop->run();
    }

    public function testConnectWithInvalidPassRejectsWithAuthenticationError()
    {
        $options = $this->getConnectionOptions();
        $loop = \React\EventLoop\Factory::create();
        $conn = new Connection($loop, array('passwd' => 'invalidpass') + $options);

        $conn->on('error', $this->expectCallableOnce());

        $conn->doConnect(function ($err, $conn) use ($loop) {
            $this->assertRegExp(
                "/^Access denied for user '.*?'@'.*?' \(using password: YES\)$/",
                $err->getMessage()
            );
            $this->assertInstanceOf('React\MySQL\Connection', $conn);
            $this->assertEquals(Connection::STATE_AUTHENTICATE_FAILED, $conn->getState());
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

        $conn->doConnect(function () { });
        $conn->doConnect(function () { });
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

    public function testQueryWithoutConnectRejects()
    {
        $options = $this->getConnectionOptions();
        $loop = \React\EventLoop\Factory::create();
        $conn = new Connection($loop, $options);

        $conn->query('SELECT 1')->then(
            $this->expectCallableNever(),
            function (\Exception $error) {
                $this->assertInstanceOf('React\MySQL\Exception', $error);
                $this->assertSame('Can\'t send command', $error->getMessage());
            }
        );
    }

    public function testPingWithoutConnectRejects()
    {
        $options = $this->getConnectionOptions();
        $loop = \React\EventLoop\Factory::create();
        $conn = new Connection($loop, $options);

        $conn->ping()->done(
            $this->expectCallableNever(),
            function (\Exception $error) {
                $this->assertInstanceOf('React\MySQL\Exception', $error);
                $this->assertSame('Can\'t send command', $error->getMessage());
            }
        );
    }

    public function testCloseWhileConnectingWillBeQueuedAfterConnection()
    {
        $this->expectOutputString('connectedclosed');
        $options = $this->getConnectionOptions();
        $loop = \React\EventLoop\Factory::create();
        $conn = new Connection($loop, $options);

        $conn->doConnect(function ($err) {
            echo $err ? $err : 'connected';
        });
        $conn->close(function () {
            echo 'closed';
        });

        $loop->run();
    }

    public function testPingAfterConnectWillEmitErrorWhenServerClosesConnection()
    {
        $this->expectOutputString('Connection lost');

        $loop = \React\EventLoop\Factory::create();

        $server = new Server(0, $loop);
        $server->on('connection', function ($connection) use ($server) {
            $server->close();
            $connection->close();
        });

        $parts = parse_url($server->getAddress());
        $options = $this->getConnectionOptions();
        $options['host'] = $parts['host'];
        $options['port'] = $parts['port'];

        $conn = new Connection($loop, $options);

        $conn->doConnect(function ($err) {
            echo $err ? $err->getMessage() : 'OK';
        });

        $loop->run();
    }

    public function testConnectWillEmitErrorWhenServerClosesConnection()
    {
        $this->expectOutputString('Connection lost');

        $loop = \React\EventLoop\Factory::create();

        $server = new Server(0, $loop);
        $server->on('connection', function ($connection) use ($server) {
            $server->close();
            $connection->close();
        });

        $parts = parse_url($server->getAddress());
        $options = $this->getConnectionOptions();
        $options['host'] = $parts['host'];
        $options['port'] = $parts['port'];

        $conn = new Connection($loop, $options);

        $conn->doConnect(function () { });
        $conn->ping()->then(
            $this->expectCallableNever(),
            function ($err) {
                echo $err->getMessage();
            }
        );

        $loop->run();
    }

    public function testPingAndCloseWhileConnectingWillBeQueuedAfterConnection()
    {
        $this->expectOutputString('connectedpingclosed');
        $options = $this->getConnectionOptions();
        $loop = \React\EventLoop\Factory::create();
        $conn = new Connection($loop, $options);

        $conn->doConnect(function ($err) {
            echo $err ? $err : 'connected';
        });
        $conn->ping()->then(function () {
            echo 'ping';
        }, function () {
            echo $err;
        });
        $conn->close(function () {
            echo 'closed';
        });

        $loop->run();
    }

    public function testPingAfterCloseWhileConnectingRejectsImmediately()
    {
        $this->expectOutputString('connectedclosed');
        $options = $this->getConnectionOptions();
        $loop = \React\EventLoop\Factory::create();
        $conn = new Connection($loop, $options);

        $conn->doConnect(function ($err) {
            echo $err ? $err : 'connected';
        });
        $conn->close(function () {
            echo 'closed';
        });

        $failed = false;
        $conn->ping()->then(null, function () use (&$failed) {
            $failed = true;
        });
        $this->assertTrue($failed);

        $loop->run();
    }

    public function testCloseWhileConnectingWithInvalidPassWillNeverFire()
    {
        $this->expectOutputString('error');
        $options = $this->getConnectionOptions();
        $loop = \React\EventLoop\Factory::create();
        $conn = new Connection($loop, array('passwd' => 'invalidpass') + $options);

        $conn->doConnect(function ($err) {
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

        $conn->on('error', $this->expectCallableNever());

        $conn->on('end', function ($conn){
            $this->assertInstanceOf('React\MySQL\Connection', $conn);
            echo 'end';
        });

        $conn->on('close', function ($conn){
            $this->assertInstanceOf('React\MySQL\Connection', $conn);
            echo 'close';
        });

        $conn->doConnect(function ($err, $conn) use ($loop) {
            $this->assertEquals(null, $err);
            $this->assertInstanceOf('React\MySQL\Connection', $conn);
            $this->assertEquals(Connection::STATE_AUTHENTICATED, $conn->getState());
        });

        $conn->ping()->then(function () use ($loop, $conn) {
            $conn->close(function ($conn) {
                $this->assertEquals($conn::STATE_CLOSED, $conn->getState());
            });
        });
        $loop->run();
    }
}
