<?php

namespace React\MySQL;

use React\EventLoop\LoopInterface;
use React\Promise\Promise;
use React\Socket\Connector;
use React\Socket\ConnectorInterface;
use React\Promise\PromiseInterface;

class Factory
{
    private $loop;
    private $connector;

    /**
     * The `Factory` is responsible for creating your [`Connection`](#connection) instance.
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
     *     function (ConnetionInterface $connection) {
     *         // client connection established (and authenticated)
     *     },
     *     function (Exception $e) {
     *         // an error occured while trying to connect or authorize client
     *     }
     * );
     * ```
     *
     * The method returns a [Promise](https://github.com/reactphp/promise) that
     * will resolve with the [`Connection`](#connection) instance on success or
     * will reject with an `Exception` if the URL is invalid or the connection
     * or authentication fails.
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
     * @param string $uri
     * @return PromiseInterface Promise<ConnectionInterface, Exception>
     */
    public function createConnection($uri)
    {
        $parts = parse_url('mysql://' . $uri);
        if (!isset($parts['scheme'], $parts['host']) || $parts['scheme'] !== 'mysql') {
            return \React\Promise\reject(new \InvalidArgumentException());
        }

        $args = array(
            'host' => $parts['host'],
            'port' => isset($parts['port']) ? $parts['port'] : 3306,
            'user' => isset($parts['user']) ? $parts['user'] : 'root',
            'passwd' => isset($parts['pass']) ? $parts['pass'] : '',
            'dbname' => isset($parts['path']) ? ltrim($parts['path'], '/') : ''
        );

        return new Promise(function ($resolve, $reject) use ($args) {
            $connection = new Connection($this->loop, $args, $this->connector);
            $connection->connect(function ($e) use ($connection, $resolve, $reject) {
                if ($e !== null) {
                    $reject($e);
                } else {
                    $this->loop->futureTick(function () use ($resolve, $connection) {
                        $resolve($connection);
                    });
                }
            });
        });
    }
}
