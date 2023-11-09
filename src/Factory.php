<?php

namespace React\MySQL;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\MySQL\Commands\AuthenticateCommand;
use React\MySQL\Io\Connection;
use React\MySQL\Io\Executor;
use React\MySQL\Io\Parser;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Promise\Timer\TimeoutException;
use React\Socket\Connector;
use React\Socket\ConnectorInterface;
use React\Socket\ConnectionInterface as SocketConnectionInterface;
use React\MySQL\Io\LazyConnection;

class Factory
{
    /** @var LoopInterface */
    private $loop;

    /** @var ConnectorInterface */
    private $connector;

    /**
     * The `Factory` is responsible for creating your [`ConnectionInterface`](#connectioninterface) instance.
     *
     * ```php
     * $factory = new React\MySQL\Factory();
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
     * $factory = new React\MySQL\Factory(null, $connector);
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
     *     function (ConnectionInterface $connection) {
     *         // client connection established (and authenticated)
     *     },
     *     function (Exception $e) {
     *         // an error occurred while trying to connect or authorize client
     *     }
     * );
     * ```
     *
     * The method returns a [Promise](https://github.com/reactphp/promise) that
     * will resolve with a [`ConnectionInterface`](#connectioninterface)
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
     * @return PromiseInterface<ConnectionInterface>
     *     Resolves with a `ConnectionInterface` on success or rejects with an `Exception` on error.
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

        $connecting->then(function (SocketConnectionInterface $stream) use ($authCommand, $deferred, $uri) {
            $executor = new Executor();
            $parser = new Parser($stream, $executor);

            $connection = new Connection($stream, $executor);
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

    /**
     * Creates a new connection.
     *
     * It helps with establishing a TCP/IP connection to your MySQL database
     * and issuing the initial authentication handshake.
     *
     * ```php
     * $connection = $factory->createLazyConnection($url);
     *
     * $connection->query(…);
     * ```
     *
     * This method immediately returns a "virtual" connection implementing the
     * [`ConnectionInterface`](#connectioninterface) that can be used to
     * interface with your MySQL database. Internally, it lazily creates the
     * underlying database connection only on demand once the first request is
     * invoked on this instance and will queue all outstanding requests until
     * the underlying connection is ready. This underlying connection will be
     * reused for all requests until it is closed. By default, idle connections
     * will be held open for 1ms (0.001s) when not used. The next request will
     * either reuse the existing connection or will automatically create a new
     * underlying connection if this idle time is expired.
     *
     * From a consumer side this means that you can start sending queries to the
     * database right away while the underlying connection may still be
     * outstanding. Because creating this underlying connection may take some
     * time, it will enqueue all outstanding commands and will ensure that all
     * commands will be executed in correct order once the connection is ready.
     * In other words, this "virtual" connection behaves just like a "real"
     * connection as described in the `ConnectionInterface` and frees you from
     * having to deal with its async resolution.
     *
     * If the underlying database connection fails, it will reject all
     * outstanding commands and will return to the initial "idle" state. This
     * means that you can keep sending additional commands at a later time which
     * will again try to open a new underlying connection. Note that this may
     * require special care if you're using transactions that are kept open for
     * longer than the idle period.
     *
     * Note that creating the underlying connection will be deferred until the
     * first request is invoked. Accordingly, any eventual connection issues
     * will be detected once this instance is first used. You can use the
     * `quit()` method to ensure that the "virtual" connection will be soft-closed
     * and no further commands can be enqueued. Similarly, calling `quit()` on
     * this instance when not currently connected will succeed immediately and
     * will not have to wait for an actual underlying connection.
     *
     * Depending on your particular use case, you may prefer this method or the
     * underlying `createConnection()` which resolves with a promise. For many
     * simple use cases it may be easier to create a lazy connection.
     *
     * The `$url` parameter must contain the database host, optional
     * authentication, port and database to connect to:
     *
     * ```php
     * $factory->createLazyConnection('user:secret@localhost:3306/database');
     * ```
     *
     * Note that both the username and password must be URL-encoded (percent-encoded)
     * if they contain special characters:
     *
     * ```php
     * $user = 'he:llo';
     * $pass = 'p@ss';
     *
     * $connection = $factory->createLazyConnection(
     *     rawurlencode($user) . ':' . rawurlencode($pass) . '@localhost:3306/db'
     * );
     * ```
     *
     * You can omit the port if you're connecting to default port `3306`:
     *
     * ```php
     * $factory->createLazyConnection('user:secret@localhost/database');
     * ```
     *
     * If you do not include authentication and/or database, then this method
     * will default to trying to connect as user `root` with an empty password
     * and no database selected. This may be useful when initially setting up a
     * database, but likely to yield an authentication error in a production system:
     *
     * ```php
     * $factory->createLazyConnection('localhost');
     * ```
     *
     * This method respects PHP's `default_socket_timeout` setting (default 60s)
     * as a timeout for establishing the underlying connection and waiting for
     * successful authentication. You can explicitly pass a custom timeout value
     * in seconds (or use a negative number to not apply a timeout) like this:
     *
     * ```php
     * $factory->createLazyConnection('localhost?timeout=0.5');
     * ```
     *
     * By default, idle connections will be held open for 1ms (0.001s) when not
     * used. The next request will either reuse the existing connection or will
     * automatically create a new underlying connection if this idle time is
     * expired. This ensures you always get a "fresh" connection and as such
     * should not be confused with a "keepalive" or "heartbeat" mechanism, as
     * this will not actively try to probe the connection. You can explicitly
     * pass a custom idle timeout value in seconds (or use a negative number to
     * not apply a timeout) like this:
     *
     * ```php
     * $factory->createLazyConnection('localhost?idle=10.0');
     * ```
     *
     * By default, the connection provides full UTF-8 support (using the
     * `utf8mb4` charset encoding). This should usually not be changed for most
     * applications nowadays, but for legacy reasons you can change this to use
     * a different ASCII-compatible charset encoding like this:
     *
     * ```php
     * $factory->createLazyConnection('localhost?charset=utf8mb4');
     * ```
     *
     * @param string $uri
     * @return ConnectionInterface
     */
    public function createLazyConnection(
        #[\SensitiveParameter]
        $uri
    ) {
        return new LazyConnection($this, $uri, $this->loop);
    }
}
