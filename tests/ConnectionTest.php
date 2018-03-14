<?php

namespace React\Tests\MySQL;

use React\MySQL\Connection;

class ConnectionTest extends BaseTestCase
{
    public function testConnectWithInvalidHostRejectsWithConnectionError()
    {
        $options = $this->getConnectionOptions();
        $loop = \React\EventLoop\Factory::create();
        $conn = new Connection($loop, array('host' => 'example.invalid') + $options);

        $conn->on('error', $this->expectCallableOnce());

        $conn->connect(function ($err, $conn) use ($loop, $options) {
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

        $conn->connect(function ($err, $conn) use ($loop, $options) {
            $this->assertEquals(sprintf(
                "Access denied for user '%s'@'%s' (using password: YES)",
                $options['user'],
                $options['host']
            ), $err->getMessage());
            $this->assertInstanceOf('React\MySQL\Connection', $conn);
            $this->assertEquals(Connection::STATE_AUTHENTICATE_FAILED, $conn->getState());
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

        $conn->connect(function ($err, $conn) use ($loop) {
            $this->assertEquals(null, $err);
            $this->assertInstanceOf('React\MySQL\Connection', $conn);
            $this->assertEquals(Connection::STATE_AUTHENTICATED, $conn->getState());
        });

        $conn->ping(function ($err, $conn) use ($loop) {
            $this->assertEquals(null, $err);
            $conn->close(function ($conn) {
                $this->assertEquals($conn::STATE_CLOSED, $conn->getState());
            });
        });
        $loop->run();
    }
}
