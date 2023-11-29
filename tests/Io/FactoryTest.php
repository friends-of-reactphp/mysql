<?php

namespace React\Tests\Mysql\Io;

use React\EventLoop\Loop;
use React\Mysql\Io\Connection;
use React\Mysql\Io\Factory;
use React\Promise\Promise;
use React\Socket\SocketServer;
use React\Tests\Mysql\BaseTestCase;

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

    public function testConnectWillUseGivenScheme()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $pending = $this->getMockBuilder('React\Promise\PromiseInterface')->getMock();
        $connector = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();
        $connector->expects($this->once())->method('connect')->with('127.0.0.1:3306')->willReturn($pending);

        $factory = new Factory($loop, $connector);
        $factory->createConnection('mysql://127.0.0.1');
    }

    public function testConnectWillRejectWhenGivenInvalidScheme()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $connector = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();

        $factory = new Factory($loop, $connector);

        $promise = $factory->createConnection('foo://127.0.0.1');

        $promise->then(null, $this->expectCallableOnceWith(
            $this->logicalAnd(
                $this->isInstanceOf('InvalidArgumentException'),
                $this->callback(function (\InvalidArgumentException $e) {
                    return $e->getMessage() === 'Invalid MySQL URI given (EINVAL)';
                }),
                $this->callback(function (\InvalidArgumentException $e) {
                    return $e->getCode() === (defined('SOCKET_EINVAL') ? SOCKET_EINVAL : 22);
                })
            )
        ));
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

        $promise->then(null, $this->expectCallableOnceWith(
            $this->logicalAnd(
                $this->isInstanceOf('InvalidArgumentException'),
                $this->callback(function (\InvalidArgumentException $e) {
                    return $e->getMessage() === 'Invalid MySQL URI given (EINVAL)';
                }),
                $this->callback(function (\InvalidArgumentException $e) {
                    return $e->getCode() === (defined('SOCKET_EINVAL') ? SOCKET_EINVAL : 22);
                })
            )
        ));
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
                $this->isInstanceOf('RuntimeException'),
                $this->callback(function (\RuntimeException $e) {
                    return !!preg_match("/^Connection to mysql:\/\/[^ ]* failed during authentication: Access denied for user '.*?'@'.*?' \(using password: YES\) \(EACCES\)$/", $e->getMessage());
                }),
                $this->callback(function (\RuntimeException $e) {
                    return $e->getCode() === (defined('SOCKET_EACCES') ? SOCKET_EACCES : 13);
                }),
                $this->callback(function (\RuntimeException $e) {
                    return !!preg_match("/^Access denied for user '.*?'@'.*?' \(using password: YES\)$/", $e->getPrevious()->getMessage());
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

        $uri = preg_replace('/:[^:]*@/', ':***@', $uri);

        $promise->then(null, $this->expectCallableOnceWith(
            $this->logicalAnd(
                $this->isInstanceOf('RuntimeException'),
                $this->callback(function (\RuntimeException $e) use ($uri) {
                    return $e->getMessage() === 'Connection to mysql://' . $uri . ' failed during authentication: Connection closed by peer (ECONNRESET)';
                }),
                $this->callback(function (\RuntimeException $e) {
                    return $e->getCode() === (defined('SOCKET_ECONNRESET') ? SOCKET_ECONNRESET : 104);
                })
            )
        ));

        Loop::run();
    }

    public function testConnectWillRejectOnExplicitTimeoutDespiteValidAuth()
    {
        $factory = new Factory();

        $uri = 'mysql://' . $this->getConnectionString() . '?timeout=0';

        $promise = $factory->createConnection($uri);

        $uri = preg_replace('/:[^:]*@/', ':***@', $uri);

        $promise->then(null, $this->expectCallableOnceWith(
            $this->logicalAnd(
                $this->isInstanceOf('RuntimeException'),
                $this->callback(function (\RuntimeException $e) use ($uri) {
                    return $e->getMessage() === 'Connection to ' . $uri . ' timed out after 0 seconds (ETIMEDOUT)';
                }),
                $this->callback(function (\RuntimeException $e) {
                    return $e->getCode() === (defined('SOCKET_ETIMEDOUT') ? SOCKET_ETIMEDOUT : 110);
                })
            )
        ));

        Loop::run();
    }

    public function testConnectWillRejectOnDefaultTimeoutFromIniDespiteValidAuth()
    {
        $factory = new Factory();

        $uri = 'mysql://' . $this->getConnectionString();

        $old = ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', '0');
        $promise = $factory->createConnection($uri);
        ini_set('default_socket_timeout', $old);

        $uri = preg_replace('/:[^:]*@/', ':***@', $uri);

        $promise->then(null, $this->expectCallableOnceWith(
            $this->logicalAnd(
                $this->isInstanceOf('RuntimeException'),
                $this->callback(function (\RuntimeException $e) use ($uri) {
                    return $e->getMessage() === 'Connection to ' . $uri . ' timed out after 0 seconds (ETIMEDOUT)';
                }),
                $this->callback(function (\RuntimeException $e) {
                    return $e->getCode() === (defined('SOCKET_ETIMEDOUT') ? SOCKET_ETIMEDOUT : 110);
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
        $factory->createConnection($uri)->then(function (Connection $connection) {
            echo 'connected.';
            $connection->quit()->then(function () {
                echo 'closed.';
            });
        });

        Loop::run();
    }

    public function testConnectWithValidAuthAndWithoutDbNameWillRunUntilQuit()
    {
        $this->expectOutputString('connected.closed.');

        $factory = new Factory();

        $uri = $this->getConnectionString(['dbname' => '']);
        $factory->createConnection($uri)->then(function (Connection $connection) {
            echo 'connected.';
            $connection->quit()->then(function () {
                echo 'closed.';
            });
        });

        Loop::run();
    }

    public function testConnectWithValidAuthWillIgnoreNegativeTimeoutAndRunUntilQuit()
    {
        $this->expectOutputString('connected.closed.');

        $factory = new Factory();

        $uri = $this->getConnectionString() . '?timeout=-1';
        $factory->createConnection($uri)->then(function (Connection $connection) {
            echo 'connected.';
            $connection->quit()->then(function () {
                echo 'closed.';
            });
        });

        Loop::run();
    }

    public function testConnectWithValidAuthCanPingAndThenQuit()
    {
        $this->expectOutputString('connected.ping.closed.');

        $factory = new Factory();

        $uri = $this->getConnectionString();
        $factory->createConnection($uri)->then(function (Connection $connection) {
            echo 'connected.';
            $connection->ping()->then(function () use ($connection) {
                echo 'ping.';
                $connection->quit()->then(function () {
                    echo 'closed.';
                });
            });
        });

        Loop::run();
    }

    public function testConnectWithValidAuthCanQueuePingAndQuit()
    {
        $this->expectOutputString('connected.ping.closed.');

        $factory = new Factory();

        $uri = $this->getConnectionString();
        $factory->createConnection($uri)->then(function (Connection $connection) {
            echo 'connected.';
            $connection->ping()->then(function () {
                echo 'ping.';
            });
            $connection->quit()->then(function () {
                echo 'closed.';
            });
        });

        Loop::run();
    }

    public function testConnectWithValidAuthQuitOnlyOnce()
    {
        $this->expectOutputString('connected.rejected.closed.');

        $factory = new Factory();

        $uri = $this->getConnectionString();
        $factory->createConnection($uri)->then(function (Connection $connection) {
            echo 'connected.';
            $connection->quit()->then(function () {
                echo 'closed.';
            });
            $connection->quit()->then(function () {
                echo 'never reached.';
            }, function () {
                echo 'rejected.';
            });
        });

        Loop::run();
    }

    public function testConnectWithValidAuthCanCloseOnlyOnce()
    {
        $this->expectOutputString('connected.closed.');

        $factory = new Factory();

        $uri = $this->getConnectionString();
        $factory->createConnection($uri)->then(function (Connection $connection) {
            echo 'connected.';
            $connection->on('close', function () {
                echo 'closed.';
            });
            $connection->on('error', function () {
                echo 'error?';
            });

            $connection->close();
            $connection->close();
        });

        Loop::run();
    }

    public function testConnectWithValidAuthCanCloseAndAbortPing()
    {
        $this->expectOutputString('connected.aborted pending (Connection closing (ECONNABORTED)).aborted queued (Connection closing (ECONNABORTED)).closed.');

        $factory = new Factory();

        $uri = $this->getConnectionString();
        $factory->createConnection($uri)->then(function (Connection $connection) {
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
        });

        Loop::run();
    }

    public function testlConnectWillRejectWhenUnderlyingConnectorRejects()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $connector = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();
        $connector->expects($this->once())->method('connect')->willReturn(\React\Promise\reject(new \RuntimeException('Failed', 123)));

        $factory = new Factory($loop, $connector);
        $promise = $factory->createConnection('user:secret@127.0.0.1');

        $promise->then(null, $this->expectCallableOnceWith(
            $this->logicalAnd(
                $this->isInstanceOf('RuntimeException'),
                $this->callback(function (\RuntimeException $e) {
                    return $e->getMessage() === 'Connection to mysql://user:***@127.0.0.1 failed: Failed';
                }),
                $this->callback(function (\RuntimeException $e) {
                    return $e->getCode() === 123;
                })
            )
        ));
    }

    public function provideUris()
    {
        return [
            [
                'localhost',
                'mysql://localhost'
            ],
            [
                'mysql://localhost',
                'mysql://localhost'
            ],
            [
                'mysql://user:pass@localhost',
                'mysql://user:***@localhost'
            ],
            [
                'mysql://user:@localhost',
                'mysql://user:***@localhost'
            ],
            [
                'mysql://user@localhost',
                'mysql://user@localhost'
            ]
        ];
    }

    /**
     * @dataProvider provideUris
     * @param string $uri
     * @param string $safe
     */
    public function testCancelConnectWillCancelPendingConnection($uri, $safe)
    {
        $pending = new Promise(function () { }, $this->expectCallableOnce());
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $connector = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();
        $connector->expects($this->once())->method('connect')->willReturn($pending);

        $factory = new Factory($loop, $connector);
        $promise = $factory->createConnection($uri);

        $promise->cancel();

        $promise->then(null, $this->expectCallableOnceWith(
            $this->logicalAnd(
                $this->isInstanceOf('RuntimeException'),
                $this->callback(function (\RuntimeException $e) use ($safe) {
                    return $e->getMessage() === 'Connection to ' . $safe . ' cancelled (ECONNABORTED)';
                }),
                $this->callback(function (\RuntimeException $e) {
                    return $e->getCode() === (defined('SOCKET_ECONNABORTED') ? SOCKET_ECONNABORTED : 103);
                })
            )
        ));
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

        $promise->then(null, $this->expectCallableOnceWith(
            $this->logicalAnd(
                $this->isInstanceOf('RuntimeException'),
                $this->callback(function (\RuntimeException $e) {
                    return $e->getMessage() === 'Connection to mysql://127.0.0.1 cancelled (ECONNABORTED)';
                }),
                $this->callback(function (\RuntimeException $e) {
                    return $e->getCode() === (defined('SOCKET_ECONNABORTED') ? SOCKET_ECONNABORTED : 103);
                })
            )
        ));
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

        $promise->then(null, $this->expectCallableOnceWith(
            $this->logicalAnd(
                $this->isInstanceOf('RuntimeException'),
                $this->callback(function (\RuntimeException $e) {
                    return $e->getMessage() === 'Connection to mysql://127.0.0.1 cancelled (ECONNABORTED)';
                }),
                $this->callback(function (\RuntimeException $e) {
                    return $e->getCode() === (defined('SOCKET_ECONNABORTED') ? SOCKET_ECONNABORTED : 103);
                })
            )
        ));
    }
}
