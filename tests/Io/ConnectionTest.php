<?php

namespace React\Tests\MySQL\Io;

use React\MySQL\Io\Connection;
use React\Socket\Server;
use React\Tests\MySQL\BaseTestCase;

class ConnectionTest extends BaseTestCase
{
    public function testConnectWithInvalidHostRejectsWithConnectionError()
    {
        $options = $this->getConnectionOptions();
        $loop = \React\EventLoop\Factory::create();
        $conn = new Connection($loop, array('host' => 'example.invalid') + $options);

        $conn->on('error', $this->expectCallableOnce());

        $conn->doConnect(function ($err, $conn) use ($loop, $options) {
            $this->assertInstanceOf('React\MySQL\Io\Connection', $conn);
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
            $this->assertInstanceOf('React\MySQL\Io\Connection', $conn);
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

    public function testQuitWithoutConnectRejects()
    {
        $options = $this->getConnectionOptions();
        $loop = \React\EventLoop\Factory::create();
        $conn = new Connection($loop, $options);

        $conn->quit()->done(
            $this->expectCallableNever(),
            function (\Exception $error) {
                $this->assertInstanceOf('React\MySQL\Exception', $error);
                $this->assertSame('Can\'t send command', $error->getMessage());
            }
        );
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

    public function testQuitWhileConnectingWillBeQueuedAfterConnection()
    {
        $this->expectOutputString('connectedclosed');
        $options = $this->getConnectionOptions();
        $loop = \React\EventLoop\Factory::create();
        $conn = new Connection($loop, $options);

        $conn->doConnect(function ($err) {
            echo $err ? $err : 'connected';
        });
        $conn->quit()->then(function () {
            echo 'closed';
        });

        $loop->run();
    }

    public function testQuitAfterQuitWhileConnectingWillBeRejected()
    {
        $options = $this->getConnectionOptions();
        $loop = \React\EventLoop\Factory::create();
        $conn = new Connection($loop, $options);

        $conn->doConnect(function ($err) { });
        $conn->quit();

        $conn->quit()->done(
            $this->expectCallableNever(),
            function (\Exception $error) {
                $this->assertInstanceOf('React\MySQL\Exception', $error);
                $this->assertSame('Can\'t send command', $error->getMessage());
            }
        );

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

        $conn->doConnect(function ($err) {
            echo $err ? $err->getMessage() : 'OK';
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

        $conn->doConnect(function () { });
        $conn->ping()->then(
            $this->expectCallableNever(),
            function ($err) {
                echo $err->getMessage();
            }
        );

        $loop->run();
    }

    public function testPingAndQuitWhileConnectingWillBeQueuedAfterConnection()
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
        $conn->quit()->then(function () {
            echo 'closed';
        });

        $loop->run();
    }

    public function testPingAfterQuitWhileConnectingRejectsImmediately()
    {
        $this->expectOutputString('connectedclosed');
        $options = $this->getConnectionOptions();
        $loop = \React\EventLoop\Factory::create();
        $conn = new Connection($loop, $options);

        $conn->doConnect(function ($err) {
            echo $err ? $err : 'connected';
        });
        $conn->quit()->then(function () {
            echo 'closed';
        });

        $failed = false;
        $conn->ping()->then(null, function () use (&$failed) {
            $failed = true;
        });
        $this->assertTrue($failed);

        $loop->run();
    }

    public function testQuitWhileConnectingWithInvalidPassWillNeverFire()
    {
        $this->expectOutputString('error');
        $options = $this->getConnectionOptions();
        $loop = \React\EventLoop\Factory::create();
        $conn = new Connection($loop, array('passwd' => 'invalidpass') + $options);

        $conn->doConnect(function ($err) {
            echo $err ? 'error' : 'connected';
        });
        $conn->quit()->then(function () {
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
            $this->assertInstanceOf('React\MySQL\Io\Connection', $conn);
            echo 'end';
        });

        $conn->on('close', function ($conn){
            $this->assertInstanceOf('React\MySQL\Io\Connection', $conn);
            echo 'close';
        });

        $conn->doConnect(function ($err, $conn) use ($loop) {
            $this->assertEquals(null, $err);
            $this->assertInstanceOf('React\MySQL\Io\Connection', $conn);
        });

        $once = $this->expectCallableOnce();
        $conn->ping()->then(function () use ($conn, $once) {
            $conn->quit()->then($once);
        });

        $loop->run();
    }
}
