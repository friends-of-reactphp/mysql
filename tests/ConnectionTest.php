<?php

namespace React\Tests\MySQL;

use React\MySQL\Connection;

class ConnectionTest extends BaseTestCase
{

    public function testConnectWithInvalidPass()
    {
        $options = $this->getConnectionOptions();
        $loop = \React\EventLoop\Factory::create();
        $conn = new Connection($loop, array('passwd' => 'invalidpass') + $options);

        $conn->connect(function ($err, $conn) use ($loop, $options) {
            $this->assertEquals(sprintf(
                "Access denied for user '%s'@'localhost' (using password: YES)",
                $options['user']
            ), $err->getMessage());
            $this->assertInstanceOf('React\MySQL\Connection', $conn);
            //$loop->stop();
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
