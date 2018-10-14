<?php

namespace React\MySQL;

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
use React\Socket\ConnectionInterface;
use React\MySQL\Io\LazyConnection;

class Factory
{
    private $loop;
    private $connector;

    /**
     * The `Factory` is responsible for creating your [`ConnectionInterface`](#connectioninterface) instance.
     * It also registers everything with the main [`EventLoop`](https://github.com/reactphp/event-loop#usage).
     *
     * ```php
     * $loop = \React\EventLoop\Factory::create();
     * $factory = new Factory($loop);
     * ```
     *
     * If you need custom connector settings (DNS resolution, TLS parameters, timeouts,
     * proxy servers etc.), you can explicitly pass a custom instance of the
     * [`ConnectorInterface`](https://github.com/reactphp/socket#connectorinterface):
     *
     * ```php
     * $connector = new \React\Socket\Connector($loop, array(
     *     'dns' => '127.0.0.1',
     *     'tcp' => array(
     *         'bindto' => '192.168.10.1:0'
     *     ),
     *     'tls' => array(
     *         'verify_peer' => false,
     *         'verify_peer_name' => false
     *     )
     * ));
     *
     * $factory = new Factory($loop, $connector);
     * ```
     *
     * @param LoopInterface $loop
     * @param ConnectorInterface|null $connector
     */
    public function __construct(LoopInterface $loop, ConnectorInterface $connector = null)
    {
        if ($connector === null) {
            $connector = new Connector($loop);
        }

        $this->loop = $loop;
        $this->connector = $connector;
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
     *         // an error occured while trying to connect or authorize client
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
     * $loop->addTimer(3.0, function () use ($promise) {
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
     * @param string $uri
     * @return PromiseInterface Promise<ConnectionInterface, Exception>
     */
    public function createConnection($uri)
    {
        $parts = parse_url('mysql://' . $uri);
        if (!isset($parts['scheme'], $parts['host']) || $parts['scheme'] !== 'mysql') {
            return \React\Promise\reject(new \InvalidArgumentException('Invalid connect uri given'));
        }

        $connecting = $this->connector->connect(
            $parts['host'] . ':' . (isset($parts['port']) ? $parts['port'] : 3306)
        );

        $deferred = new Deferred(function ($_, $reject) use ($connecting) {
            // connection cancelled, start with rejecting attempt, then clean up
            $reject(new \RuntimeException('Connection to database server cancelled'));

            // either close successful connection or cancel pending connection attempt
            $connecting->then(function (ConnectionInterface $connection) {
                $connection->close();
            });
            $connecting->cancel();
        });

        $connecting->then(function (ConnectionInterface $stream) use ($parts, $deferred) {
            $executor = new Executor();
            $parser = new Parser($stream, $executor);

            $connection = new Connection($stream, $executor);
            $command = $executor->enqueue(new AuthenticateCommand(
                isset($parts['user']) ? $parts['user'] : 'root',
                isset($parts['pass']) ? $parts['pass'] : '',
                isset($parts['path']) ? ltrim($parts['path'], '/') : ''
            ));
            $parser->start();

            $command->on('success', function () use ($deferred, $connection) {
                $deferred->resolve($connection);
            });
            $command->on('error', function ($error) use ($deferred, $stream) {
                $deferred->reject($error);
                $stream->close();
            });
        }, function ($error) use ($deferred) {
            $deferred->reject(new \RuntimeException('Unable to connect to database server', 0, $error));
        });

        $args = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $args);
        }

        // use timeout from explicit ?timeout=x parameter or default to PHP's default_socket_timeout (60)
        $timeout = (float) isset($args['timeout']) ? $args['timeout'] : ini_get("default_socket_timeout");
        if ($timeout < 0) {
            return $deferred->promise();
        }

        return \React\Promise\Timer\timeout($deferred->promise(), $timeout, $this->loop)->then(null, function ($e) {
            if ($e instanceof TimeoutException) {
                throw new \RuntimeException(
                    'Connection to database server timed out after ' . $e->getTimeout() . ' seconds'
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
     * underlying database connection (which may take some time) only once the
     * first request is invoked on this instance and will queue all outstanding
     * requests until the underlying connection is ready.
     *
     * From a consumer side this means that you can start sending queries to the
     * database right away while the actual connection may still be outstanding.
     * It will ensure that all commands will be executed in the order they are
     * enqueued once the connection is ready. If the database connection fails,
     * it will emit an `error` event, reject all outstanding commands and `close`
     * the connection as described in the `ConnectionInterface`. In other words,
     * it behaves just like a real connection and frees you from having to deal
     * with its async resolution.
     *
     * Note that creating the underlying connection will be deferred until the
     * first request is invoked. Accordingly, any eventual connection issues
     * will be detected once this instance is first used. Similarly, calling
     * `quit()` on this instance before invoking any requests will succeed
     * immediately and will not wait for an actual underlying connection.
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
     * @param string $uri
     * @return ConnectionInterface
     */
    public function createLazyConnection($uri)
    {
        return new LazyConnection($this, $uri);
    }
}
