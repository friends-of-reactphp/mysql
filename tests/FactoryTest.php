<?php

namespace React\Tests\MySQL;

use React\MySQL\ConnectionInterface;
use React\MySQL\Factory;
use React\Socket\Server;
use React\Promise\Promise;

class FactoryTest extends BaseTestCase
{
    public function testConnectWillUseHostAndDefaultPort()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $pending = $this->getMockBuilder('React\Promise\PromiseInterface')->getMock();
        $connector = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();
        $connector->expects($this->once())->method('connect')->with('127.0.0.1:3306')->willReturn($pending);

        $factory = new Factory($loop, $connector);
        $factory->createConnection('127.0.0.1');
    }

    public function testConnectWillUseGivenHostAndGivenPort()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $pending = $this->getMockBuilder('React\Promise\PromiseInterface')->getMock();
        $connector = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();
        $connector->expects($this->once())->method('connect')->with('127.0.0.1:1234')->willReturn($pending);

        $factory = new Factory($loop, $connector);
        $factory->createConnection('127.0.0.1:1234');
    }

    public function testConnectWillUseGivenUserInfoAsDatabaseCredentialsAfterUrldecoding()
    {
        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(array('write'))->getMock();
        $connection->expects($this->once())->method('write')->with($this->stringContains("user!\0"));

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $connector = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();
        $connector->expects($this->once())->method('connect')->with('127.0.0.1:3306')->willReturn(\React\Promise\resolve($connection));

        $factory = new Factory($loop, $connector);
        $promise = $factory->createConnection('user%21@127.0.0.1');

        $promise->then($this->expectCallableNever(), $this->expectCallableNever());

        $connection->emit('data', array("\x33\0\0\0" . "\x0a" . "mysql\0" . str_repeat("\0", 44)));
    }

    public function testConnectWillUseGivenPathAsDatabaseNameAfterUrldecoding()
    {
        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(array('write'))->getMock();
        $connection->expects($this->once())->method('write')->with($this->stringContains("test database\0"));

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $connector = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();
        $connector->expects($this->once())->method('connect')->with('127.0.0.1:3306')->willReturn(\React\Promise\resolve($connection));

        $factory = new Factory($loop, $connector);
        $promise = $factory->createConnection('127.0.0.1/test%20database');

        $promise->then($this->expectCallableNever(), $this->expectCallableNever());

        $connection->emit('data', array("\x33\0\0\0" . "\x0a" . "mysql\0" . str_repeat("\0", 44)));
    }

    public function testConnectWithInvalidUriWillRejectWithoutConnecting()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $connector = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();
        $connector->expects($this->never())->method('connect');

        $factory = new Factory($loop, $connector);
        $promise = $factory->createConnection('///');

        $this->assertInstanceof('React\Promise\PromiseInterface', $promise);
        $promise->then(null, $this->expectCallableOnce());
    }

    public function testConnectWithInvalidHostRejectsWithConnectionError()
    {
        $loop = \React\EventLoop\Factory::create();
        $factory = new Factory($loop);

        $uri = $this->getConnectionString(array('host' => 'example.invalid'));
        $promise = $factory->createConnection($uri);

        $promise->then(null, $this->expectCallableOnce());

        $loop->run();
    }

    public function testConnectWithInvalidPassRejectsWithAuthenticationError()
    {
        $loop = \React\EventLoop\Factory::create();
        $factory = new Factory($loop);

        $uri = $this->getConnectionString(array('passwd' => 'invalidpass'));
        $promise = $factory->createConnection($uri);

        $promise->then(null, $this->expectCallableOnceWith(
            $this->logicalAnd(
                $this->isInstanceOf('Exception'),
                $this->callback(function (\Exception $e) {
                    return !!preg_match("/^Access denied for user '.*?'@'.*?' \(using password: YES\)$/", $e->getMessage());
                })
            )
        ));

        $loop->run();
    }

    public function testConnectWillRejectWhenServerClosesConnection()
    {
        $loop = \React\EventLoop\Factory::create();
        $factory = new Factory($loop);

        $server = new Server(0, $loop);
        $server->on('connection', function ($connection) use ($server) {
            $server->close();
            $connection->close();
        });

        $parts = parse_url($server->getAddress());
        $uri = $this->getConnectionString(array('host' => $parts['host'], 'port' => $parts['port']));

        $promise = $factory->createConnection($uri);
        $promise->then(null, $this->expectCallableOnce());

        $loop->run();
    }

    public function testConnectWillRejectOnExplicitTimeoutDespiteValidAuth()
    {
        $loop = \React\EventLoop\Factory::create();
        $factory = new Factory($loop);

        $uri = $this->getConnectionString() . '?timeout=0';

        $promise = $factory->createConnection($uri);

        $promise->then(null, $this->expectCallableOnceWith(
            $this->logicalAnd(
                $this->isInstanceOf('Exception'),
                $this->callback(function (\Exception $e) {
                    return $e->getMessage() === 'Connection to database server timed out after 0 seconds';
                })
            )
        ));

        $loop->run();
    }

    public function testConnectWillRejectOnDefaultTimeoutFromIniDespiteValidAuth()
    {
        $loop = \React\EventLoop\Factory::create();
        $factory = new Factory($loop);

        $uri = $this->getConnectionString();

        $old = ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', '0');
        $promise = $factory->createConnection($uri);
        ini_set('default_socket_timeout', $old);

        $promise->then(null, $this->expectCallableOnceWith(
            $this->logicalAnd(
                $this->isInstanceOf('Exception'),
                $this->callback(function (\Exception $e) {
                    return $e->getMessage() === 'Connection to database server timed out after 0 seconds';
                })
            )
        ));

        $loop->run();
    }

    public function testConnectWithValidAuthWillRunUntilQuit()
    {
        $this->expectOutputString('connected.closed.');

        $loop = \React\EventLoop\Factory::create();
        $factory = new Factory($loop);

        $uri = $this->getConnectionString();
        $factory->createConnection($uri)->then(function (ConnectionInterface $connection) {
            echo 'connected.';
            $connection->quit()->then(function () {
                echo 'closed.';
            });
        }, 'printf')->then(null, 'printf');

        $loop->run();
    }

    public function testConnectWithValidAuthAndWithoutDbNameWillRunUntilQuit()
    {
        $this->expectOutputString('connected.closed.');

        $loop = \React\EventLoop\Factory::create();
        $factory = new Factory($loop);

        $uri = $this->getConnectionString(array('dbname' => ''));
        $factory->createConnection($uri)->then(function (ConnectionInterface $connection) {
            echo 'connected.';
            $connection->quit()->then(function () {
                echo 'closed.';
            });
        }, 'printf')->then(null, 'printf');

        $loop->run();
    }

    public function testConnectWithValidAuthWillIgnoreNegativeTimeoutAndRunUntilQuit()
    {
        $this->expectOutputString('connected.closed.');

        $loop = \React\EventLoop\Factory::create();
        $factory = new Factory($loop);

        $uri = $this->getConnectionString() . '?timeout=-1';
        $factory->createConnection($uri)->then(function (ConnectionInterface $connection) {
            echo 'connected.';
            $connection->quit()->then(function () {
                echo 'closed.';
            });
        }, 'printf')->then(null, 'printf');

        $loop->run();
    }

    public function testConnectWithValidAuthCanPingAndThenQuit()
    {
        $this->expectOutputString('connected.ping.closed.');

        $loop = \React\EventLoop\Factory::create();
        $factory = new Factory($loop);

        $uri = $this->getConnectionString();
        $factory->createConnection($uri)->then(function (ConnectionInterface $connection) {
            echo 'connected.';
            $connection->ping()->then(function () use ($connection) {
                echo 'ping.';
                $connection->quit()->then(function () {
                    echo 'closed.';
                });
            });

        }, 'printf')->then(null, 'printf');

        $loop->run();
    }

    public function testConnectWithValidAuthCanQueuePingAndQuit()
    {
        $this->expectOutputString('connected.ping.closed.');

        $loop = \React\EventLoop\Factory::create();
        $factory = new Factory($loop);

        $uri = $this->getConnectionString();
        $factory->createConnection($uri)->then(function (ConnectionInterface $connection) {
            echo 'connected.';
            $connection->ping()->then(function () {
                echo 'ping.';
            });
            $connection->quit()->then(function () {
                echo 'closed.';
            });
        }, 'printf')->then(null, 'printf');

        $loop->run();
    }

    public function testConnectWithValidAuthQuitOnlyOnce()
    {
        $this->expectOutputString('connected.closed.');

        $loop = \React\EventLoop\Factory::create();
        $factory = new Factory($loop);

        $uri = $this->getConnectionString();
        $factory->createConnection($uri)->then(function (ConnectionInterface $connection) {
            echo 'connected.';
            $connection->quit()->then(function () {
                echo 'closed.';
            });
            $connection->quit()->then(function () {
                echo 'closed.';
            });
        }, 'printf')->then(null, 'printf');

        $loop->run();
    }

    public function testConnectWithValidAuthCanCloseOnlyOnce()
    {
        $this->expectOutputString('connected.closed.');

        $loop = \React\EventLoop\Factory::create();
        $factory = new Factory($loop);

        $uri = $this->getConnectionString();
        $factory->createConnection($uri)->then(function (ConnectionInterface $connection) {
            echo 'connected.';
            $connection->on('close', function () {
                echo 'closed.';
            });
            $connection->on('error', function () {
                echo 'error?';
            });

            $connection->close();
            $connection->close();
        }, 'printf')->then(null, 'printf');

        $loop->run();
    }

    public function testConnectWithValidAuthCanCloseAndAbortPing()
    {
        $this->expectOutputString('connected.aborted pending (Connection lost).aborted queued (Connection lost).closed.');

        $loop = \React\EventLoop\Factory::create();
        $factory = new Factory($loop);

        $uri = $this->getConnectionString();
        $factory->createConnection($uri)->then(function (ConnectionInterface $connection) {
            echo 'connected.';
            $connection->on('close', function () {
                echo 'closed.';
            });
            $connection->on('error', function () {
                echo 'error?';
            });

            $connection->ping()->then(null, function ($e) {
                echo 'aborted pending (' . $e->getMessage() .').';
            });
            $connection->ping()->then(null, function ($e) {
                echo 'aborted queued (' . $e->getMessage() . ').';
            });
            $connection->close();
        }, 'printf')->then(null, 'printf');

        $loop->run();
    }

    public function testCancelConnectWillCancelPendingConnection()
    {
        $pending = new Promise(function () { }, $this->expectCallableOnce());
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $connector = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();
        $connector->expects($this->once())->method('connect')->willReturn($pending);

        $factory = new Factory($loop, $connector);
        $promise = $factory->createConnection('127.0.0.1');

        $promise->cancel();

        $promise->then(null, $this->expectCallableOnceWith($this->isInstanceOf('RuntimeException')));
        $promise->then(null, $this->expectCallableOnceWith($this->callback(function ($e) {
            return ($e->getMessage() === 'Connection to database server cancelled');
        })));
    }

    public function testCancelConnectWillCancelPendingConnectionWithRuntimeException()
    {
        $pending = new Promise(function () { }, function () {
            throw new \UnexpectedValueException('ignored');
        });
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $connector = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();
        $connector->expects($this->once())->method('connect')->willReturn($pending);

        $factory = new Factory($loop, $connector);
        $promise = $factory->createConnection('127.0.0.1');

        $promise->cancel();

        $promise->then(null, $this->expectCallableOnceWith($this->isInstanceOf('RuntimeException')));
        $promise->then(null, $this->expectCallableOnceWith($this->callback(function ($e) {
            return ($e->getMessage() === 'Connection to database server cancelled');
        })));
    }

    public function testCancelConnectDuringAuthenticationWillCloseConnection()
    {
        $connection = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $connection->expects($this->once())->method('close');

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $connector = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();
        $connector->expects($this->once())->method('connect')->willReturn(\React\Promise\resolve($connection));

        $factory = new Factory($loop, $connector);
        $promise = $factory->createConnection('127.0.0.1');

        $promise->cancel();

        $promise->then(null, $this->expectCallableOnceWith($this->isInstanceOf('RuntimeException')));
        $promise->then(null, $this->expectCallableOnceWith($this->callback(function ($e) {
            return ($e->getMessage() === 'Connection to database server cancelled');
        })));
    }

    public function testConnectLazyWithAnyAuthWillQuitWithoutRunning()
    {
        $this->expectOutputString('closed.');

        $loop = \React\EventLoop\Factory::create();
        $factory = new Factory($loop);

        $uri = 'mysql://random:pass@host';
        $connection = $factory->createLazyConnection($uri);

        $connection->quit()->then(function () {
            echo 'closed.';
        });
    }

    public function testConnectLazyWithValidAuthWillRunUntilQuitAfterPing()
    {
        $this->expectOutputString('closed.');

        $loop = \React\EventLoop\Factory::create();
        $factory = new Factory($loop);

        $uri = $this->getConnectionString();
        $connection = $factory->createLazyConnection($uri);

        $connection->ping();

        $connection->quit()->then(function () {
            echo 'closed.';
        });

        $loop->run();
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testConnectLazyWithValidAuthWillRunUntilIdleTimerAfterPingEvenWithoutQuit()
    {
        $loop = \React\EventLoop\Factory::create();
        $factory = new Factory($loop);

        $uri = $this->getConnectionString() . '?idle=0';
        $connection = $factory->createLazyConnection($uri);

        $connection->ping();

        $loop->run();
    }

    public function testConnectLazyWithInvalidAuthWillRejectPingButWillNotEmitErrorOrClose()
    {
        $loop = \React\EventLoop\Factory::create();
        $factory = new Factory($loop);

        $uri = $this->getConnectionString(array('passwd' => 'invalidpass'));
        $connection = $factory->createLazyConnection($uri);

        $connection->on('error', $this->expectCallableNever());
        $connection->on('close', $this->expectCallableNever());

        $connection->ping()->then(null, $this->expectCallableOnce());

        $loop->run();
    }

    public function testConnectLazyWithValidAuthWillPingBeforeQuitButNotAfter()
    {
        $this->expectOutputString('ping.closed.');

        $loop = \React\EventLoop\Factory::create();
        $factory = new Factory($loop);

        $uri = $this->getConnectionString();
        $connection = $factory->createLazyConnection($uri);

        $connection->ping()->then(function () {
            echo 'ping.';
        });

        $connection->quit()->then(function () {
            echo 'closed.';
        });

        $connection->ping()->then(function () {
            echo 'never reached';
        });

        $loop->run();
    }
}
