<?php

namespace React\Tests\MySQL;

use React\EventLoop\Loop;
use React\MySQL\ConnectionInterface;
use React\MySQL\Factory;
use React\Socket\SocketServer;
use React\Promise\Promise;

class FactoryTest extends BaseTestCase
{
    public function testConstructWithoutLoopAssignsLoopAutomatically()
    {
        $factory = new Factory();

        $ref = new \ReflectionProperty($factory, 'loop');
        $ref->setAccessible(true);
        $loop = $ref->getValue($factory);

        $this->assertInstanceOf('React\EventLoop\LoopInterface', $loop);
    }

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
        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(['write'])->getMock();
        $connection->expects($this->once())->method('write')->with($this->stringContains("user!\0"));

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $connector = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();
        $connector->expects($this->once())->method('connect')->with('127.0.0.1:3306')->willReturn(\React\Promise\resolve($connection));

        $factory = new Factory($loop, $connector);
        $promise = $factory->createConnection('user%21@127.0.0.1');

        $promise->then($this->expectCallableNever(), $this->expectCallableNever());

        $connection->emit('data', ["\x33\0\0\0" . "\x0a" . "mysql\0" . str_repeat("\0", 44)]);
    }

    public function testConnectWillUseGivenPathAsDatabaseNameAfterUrldecoding()
    {
        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(['write'])->getMock();
        $connection->expects($this->once())->method('write')->with($this->stringContains("test database\0"));

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $connector = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();
        $connector->expects($this->once())->method('connect')->with('127.0.0.1:3306')->willReturn(\React\Promise\resolve($connection));

        $factory = new Factory($loop, $connector);
        $promise = $factory->createConnection('127.0.0.1/test%20database');

        $promise->then($this->expectCallableNever(), $this->expectCallableNever());

        $connection->emit('data', ["\x33\0\0\0" . "\x0a" . "mysql\0" . str_repeat("\0", 44)]);
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

    public function testConnectWithInvalidCharsetWillRejectWithoutConnecting()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $connector = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();
        $connector->expects($this->never())->method('connect');

        $factory = new Factory($loop, $connector);
        $promise = $factory->createConnection('localhost?charset=unknown');

        $this->assertInstanceof('React\Promise\PromiseInterface', $promise);
        $promise->then(null, $this->expectCallableOnce());
    }

    public function testConnectWithInvalidHostRejectsWithConnectionError()
    {
        $factory = new Factory();

        $uri = $this->getConnectionString(['host' => 'example.invalid']);
        $promise = $factory->createConnection($uri);

        $promise->then(null, $this->expectCallableOnce());

        Loop::run();
    }

    public function testConnectWithInvalidPassRejectsWithAuthenticationError()
    {
        $factory = new Factory();

        $uri = $this->getConnectionString(['passwd' => 'invalidpass']);
        $promise = $factory->createConnection($uri);

        $promise->then(null, $this->expectCallableOnceWith(
            $this->logicalAnd(
                $this->isInstanceOf('Exception'),
                $this->callback(function (\Exception $e) {
                    return !!preg_match("/^Access denied for user '.*?'@'.*?' \(using password: YES\)$/", $e->getMessage());
                })
            )
        ));

        Loop::run();
    }

    public function testConnectWillRejectWhenServerClosesConnection()
    {
        $factory = new Factory();

        $socket = new SocketServer('127.0.0.1:0', []);
        $socket->on('connection', function ($connection) use ($socket) {
            $socket->close();
            $connection->close();
        });

        $parts = parse_url($socket->getAddress());
        $uri = $this->getConnectionString(['host' => $parts['host'], 'port' => $parts['port']]);

        $promise = $factory->createConnection($uri);
        $promise->then(null, $this->expectCallableOnce());

        Loop::run();
    }

    public function testConnectWillRejectOnExplicitTimeoutDespiteValidAuth()
    {
        $factory = new Factory();

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

        Loop::run();
    }

    public function testConnectWillRejectOnDefaultTimeoutFromIniDespiteValidAuth()
    {
        $factory = new Factory();

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

        Loop::run();
    }

    public function testConnectWithValidAuthWillRunUntilQuit()
    {
        $this->expectOutputString('connected.closed.');

        $factory = new Factory();

        $uri = $this->getConnectionString();
        $factory->createConnection($uri)->then(function (ConnectionInterface $connection) {
            echo 'connected.';
            $connection->quit()->then(function () {
                echo 'closed.';
            });
        }, 'printf')->then(null, 'printf');

        Loop::run();
    }

    public function testConnectWithValidAuthAndWithoutDbNameWillRunUntilQuit()
    {
        $this->expectOutputString('connected.closed.');

        $factory = new Factory();

        $uri = $this->getConnectionString(['dbname' => '']);
        $factory->createConnection($uri)->then(function (ConnectionInterface $connection) {
            echo 'connected.';
            $connection->quit()->then(function () {
                echo 'closed.';
            });
        }, 'printf')->then(null, 'printf');

        Loop::run();
    }

    public function testConnectWithValidAuthWillIgnoreNegativeTimeoutAndRunUntilQuit()
    {
        $this->expectOutputString('connected.closed.');

        $factory = new Factory();

        $uri = $this->getConnectionString() . '?timeout=-1';
        $factory->createConnection($uri)->then(function (ConnectionInterface $connection) {
            echo 'connected.';
            $connection->quit()->then(function () {
                echo 'closed.';
            });
        }, 'printf')->then(null, 'printf');

        Loop::run();
    }

    public function testConnectWithValidAuthCanPingAndThenQuit()
    {
        $this->expectOutputString('connected.ping.closed.');

        $factory = new Factory();

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

        Loop::run();
    }

    public function testConnectWithValidAuthCanQueuePingAndQuit()
    {
        $this->expectOutputString('connected.ping.closed.');

        $factory = new Factory();

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

        Loop::run();
    }

    public function testConnectWithValidAuthQuitOnlyOnce()
    {
        $this->expectOutputString('connected.closed.');

        $factory = new Factory();

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

        Loop::run();
    }

    public function testConnectWithValidAuthCanCloseOnlyOnce()
    {
        $this->expectOutputString('connected.closed.');

        $factory = new Factory();

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

        Loop::run();
    }

    public function testConnectWithValidAuthCanCloseAndAbortPing()
    {
        $this->expectOutputString('connected.aborted pending (Connection lost).aborted queued (Connection lost).closed.');

        $factory = new Factory();

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

        Loop::run();
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

        $factory = new Factory();

        $uri = 'mysql://random:pass@host';
        $connection = $factory->createLazyConnection($uri);

        $connection->quit()->then(function () {
            echo 'closed.';
        });
    }

    public function testConnectLazyWithValidAuthWillRunUntilQuitAfterPing()
    {
        $this->expectOutputString('closed.');

        $factory = new Factory();

        $uri = $this->getConnectionString();
        $connection = $factory->createLazyConnection($uri);

        $connection->ping();

        $connection->quit()->then(function () {
            echo 'closed.';
        });

        Loop::run();
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testConnectLazyWithValidAuthWillRunUntilIdleTimerAfterPingEvenWithoutQuit()
    {
        $factory = new Factory();

        $uri = $this->getConnectionString() . '?idle=0';
        $connection = $factory->createLazyConnection($uri);

        $connection->ping();

        Loop::run();
    }

    public function testConnectLazyWithInvalidAuthWillRejectPingButWillNotEmitErrorOrClose()
    {
        $factory = new Factory();

        $uri = $this->getConnectionString(['passwd' => 'invalidpass']);
        $connection = $factory->createLazyConnection($uri);

        $connection->on('error', $this->expectCallableNever());
        $connection->on('close', $this->expectCallableNever());

        $connection->ping()->then(null, $this->expectCallableOnce());

        Loop::run();
    }

    public function testConnectLazyWithValidAuthWillPingBeforeQuitButNotAfter()
    {
        $this->expectOutputString('ping.closed.');

        $factory = new Factory();

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

        Loop::run();
    }
}
