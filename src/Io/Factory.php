<?php

namespace React\Mysql\Io;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Mysql\Commands\AuthenticateCommand;
use React\Mysql\Exception;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Promise\Timer\TimeoutException;
use React\Socket\Connector;
use React\Socket\ConnectorInterface;
use React\Socket\ConnectionInterface as SocketConnectionInterface;

/**
 * @internal
 * @see \React\Mysql\MysqlClient
 */
class Factory
{
    /** @var LoopInterface */
    private $loop;

    /** @var ConnectorInterface */
    private $connector;

    /**
     * The `Factory` is responsible for creating an internal `Connection` instance.
     *
     * ```php
     * $factory = new React\Mysql\Io\Factory();
     * ```
     *
     * This class takes an optional `LoopInterface|null $loop` parameter that can be used to
     * pass the event loop instance to use for this object. You can use a `null` value
     * here in order to use the [default loop](https://github.com/reactphp/event-loop#loop).
     * This value SHOULD NOT be given unless you're sure you want to explicitly use a
     * given event loop instance.
     *
     * If you need custom connector settings (DNS resolution, TLS parameters, timeouts,
     * proxy servers etc.), you can explicitly pass a custom instance of the
     * [`ConnectorInterface`](https://github.com/reactphp/socket#connectorinterface):
     *
     * ```php
     * $connector = new React\Socket\Connector([
     *     'dns' => '127.0.0.1',
     *     'tcp' => [
     *         'bindto' => '192.168.10.1:0'
     *     ],
     *     'tls' => [
     *         'verify_peer' => false,
     *         'verify_peer_name' => false
     *     ]
     * ]);
     *
     * $factory = new React\Mysql\Factory(null, $connector);
     * ```
     *
     * @param ?LoopInterface $loop
     * @param ?ConnectorInterface $connector
     */
    public function __construct(LoopInterface $loop = null, ConnectorInterface $connector = null)
    {
        $this->loop = $loop ?: Loop::get();
        $this->connector = $connector ?: new Connector([], $this->loop);
    }

    /**
     * Creates a new connection.
     *
     * It helps with establishing a TCP/IP connection to your MySQL database
     * and issuing the initial authentication handshake.
     *
     * ```php
     * $factory->createConnection($url)->then(
     *     function (Connection $connection) {
     *         // client connection established (and authenticated)
     *     },
     *     function (Exception $e) {
     *         // an error occurred while trying to connect or authorize client
     *     }
     * );
     * ```
     *
     * The method returns a [Promise](https://github.com/reactphp/promise) that
     * will resolve with an internal `Connection`
     * instance on success or will reject with an `Exception` if the URL is
     * invalid or the connection or authentication fails.
     *
     * The returned Promise is implemented in such a way that it can be
     * cancelled when it is still pending. Cancelling a pending promise will
     * reject its value with an Exception and will cancel the underlying TCP/IP
     * connection attempt and/or MySQL authentication.
     *
     * ```php
     * $promise = $factory->createConnection($url);
     *
     * Loop::addTimer(3.0, function () use ($promise) {
     *     $promise->cancel();
     * });
     * ```
     *
     * The `$url` parameter must contain the database host, optional
     * authentication, port and database to connect to:
     *
     * ```php
     * $factory->createConnection('user:secret@localhost:3306/database');
     * ```
     *
     * Note that both the username and password must be URL-encoded (percent-encoded)
     * if they contain special characters:
     *
     * ```php
     * $user = 'he:llo';
     * $pass = 'p@ss';
     *
     * $promise = $factory->createConnection(
     *     rawurlencode($user) . ':' . rawurlencode($pass) . '@localhost:3306/db'
     * );
     * ```
     *
     * You can omit the port if you're connecting to default port `3306`:
     *
     * ```php
     * $factory->createConnection('user:secret@localhost/database');
     * ```
     *
     * If you do not include authentication and/or database, then this method
     * will default to trying to connect as user `root` with an empty password
     * and no database selected. This may be useful when initially setting up a
     * database, but likely to yield an authentication error in a production system:
     *
     * ```php
     * $factory->createConnection('localhost');
     * ```
     *
     * This method respects PHP's `default_socket_timeout` setting (default 60s)
     * as a timeout for establishing the connection and waiting for successful
     * authentication. You can explicitly pass a custom timeout value in seconds
     * (or use a negative number to not apply a timeout) like this:
     *
     * ```php
     * $factory->createConnection('localhost?timeout=0.5');
     * ```
     *
     * By default, the connection provides full UTF-8 support (using the
     * `utf8mb4` charset encoding). This should usually not be changed for most
     * applications nowadays, but for legacy reasons you can change this to use
     * a different ASCII-compatible charset encoding like this:
     *
     * ```php
     * $factory->createConnection('localhost?charset=utf8mb4');
     * ```
     *
     * @param string $uri
     * @return PromiseInterface<Connection>
     *     Resolves with a `Connection` on success or rejects with an `Exception` on error.
     */
    public function createConnection(
        #[\SensitiveParameter]
        $uri
    ) {
        if (strpos($uri, '://') === false) {
            $uri = 'mysql://' . $uri;
        }

        $parts = parse_url($uri);
        $uri = preg_replace('#:[^:/]*@#', ':***@', $uri);
        if (!isset($parts['scheme'], $parts['host']) || $parts['scheme'] !== 'mysql') {
            return \React\Promise\reject(new \InvalidArgumentException(
                'Invalid MySQL URI given (EINVAL)',
                \defined('SOCKET_EINVAL') ? \SOCKET_EINVAL : 22
            ));
        }

        $args = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $args);
        }

        try {
            $authCommand = new AuthenticateCommand(
                isset($parts['user']) ? rawurldecode($parts['user']) : 'root',
                isset($parts['pass']) ? rawurldecode($parts['pass']) : '',
                isset($parts['path']) ? rawurldecode(ltrim($parts['path'], '/')) : '',
                isset($args['charset']) ? $args['charset'] : 'utf8mb4'
            );
        } catch (\InvalidArgumentException $e) {
            return \React\Promise\reject($e);
        }

        $connecting = $this->connector->connect(
            $parts['host'] . ':' . (isset($parts['port']) ? $parts['port'] : 3306)
        );

        $deferred = new Deferred(function ($_, $reject) use ($connecting, $uri) {
            // connection cancelled, start with rejecting attempt, then clean up
            $reject(new \RuntimeException(
                'Connection to ' . $uri . ' cancelled (ECONNABORTED)',
                \defined('SOCKET_ECONNABORTED') ? \SOCKET_ECONNABORTED : 103
            ));

            // either close successful connection or cancel pending connection attempt
            $connecting->then(function (SocketConnectionInterface $connection) {
                $connection->close();
            }, function () {
                // ignore to avoid reporting unhandled rejection
            });
            $connecting->cancel();
        });

        $idlePeriod = isset($args['idle']) ? (float) $args['idle'] : null;
        $connecting->then(function (SocketConnectionInterface $stream) use ($authCommand, $deferred, $uri, $idlePeriod) {
            $executor = new Executor();
            $parser = new Parser($stream, $executor);

            $connection = new Connection($stream, $executor, $parser, $this->loop, $idlePeriod);
            $command = $executor->enqueue($authCommand);
            $parser->start();

            $command->on('success', function () use ($deferred, $connection) {
                $deferred->resolve($connection);
            });
            $command->on('error', function (\Exception $error) use ($deferred, $stream, $uri) {
                $const = '';
                $errno = $error->getCode();
                if ($error instanceof Exception) {
                    $const = ' (EACCES)';
                    $errno = \defined('SOCKET_EACCES') ? \SOCKET_EACCES : 13;
                }

                $deferred->reject(new \RuntimeException(
                    'Connection to ' . $uri . ' failed during authentication: ' . $error->getMessage() . $const,
                    $errno,
                    $error
                ));
                $stream->close();
            });
        }, function (\Exception $error) use ($deferred, $uri) {
            $deferred->reject(new \RuntimeException(
                'Connection to ' . $uri . ' failed: ' . $error->getMessage(),
                $error->getCode(),
                $error
            ));
        });

        // use timeout from explicit ?timeout=x parameter or default to PHP's default_socket_timeout (60)
        $timeout = (float) isset($args['timeout']) ? $args['timeout'] : ini_get("default_socket_timeout");
        if ($timeout < 0) {
            return $deferred->promise();
        }

        return \React\Promise\Timer\timeout($deferred->promise(), $timeout, $this->loop)->then(null, function ($e) use ($uri) {
            if ($e instanceof TimeoutException) {
                throw new \RuntimeException(
                    'Connection to ' . $uri . ' timed out after ' . $e->getTimeout() . ' seconds (ETIMEDOUT)',
                    \defined('SOCKET_ETIMEDOUT') ? \SOCKET_ETIMEDOUT : 110
                );
            }
            throw $e;
        });
    }
}
