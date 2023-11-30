<?php

namespace React\Mysql;

use Evenement\EventEmitter;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Mysql\Io\Connection;
use React\Mysql\Io\Factory;
use React\Promise\Promise;
use React\Socket\ConnectorInterface;
use React\Stream\ReadableStreamInterface;

/**
 * This class represents a connection that is responsible for communicating
 * with your MySQL server instance, managing the connection state and sending
 * your database queries.
 *
 * Besides defining a few methods, this class also implements the
 * `EventEmitterInterface` which allows you to react to certain events:
 *
 * error event:
 *     The `error` event will be emitted once a fatal error occurs, such as
 *     when the connection is lost or is invalid.
 *     The event receives a single `Exception` argument for the error instance.
 *
 *     ```php
 *     $mysql->on('error', function (Exception $e) {
 *         echo 'Error: ' . $e->getMessage() . PHP_EOL;
 *     });
 *     ```
 *
 *     This event will only be triggered for fatal errors and will be followed
 *     by closing the connection. It is not to be confused with "soft" errors
 *     caused by invalid SQL queries.
 *
 * close event:
 *     The `close` event will be emitted once the connection closes (terminates).
 *
 *     ```php
 *     $mysql->on('close', function () {
 *         echo 'Connection closed' . PHP_EOL;
 *     });
 *     ```
 *
 *     See also the [`close()`](#close) method.
 *
 * @final
 */
class MysqlClient extends EventEmitter
{
    private $factory;
    private $uri;
    private $connecting;
    private $closed = false;
    private $busy = false;

    /**
     * @var Connection|null
     */
    private $disconnecting;

    private $loop;
    private $idlePeriod = 0.001;
    private $idleTimer;
    private $pending = 0;

    public function __construct(
        #[\SensitiveParameter]
        $uri,
        ConnectorInterface $connector = null,
        LoopInterface $loop = null
    ) {
        $args = [];
        \parse_str((string) \parse_url($uri, \PHP_URL_QUERY), $args);
        if (isset($args['idle'])) {
            $this->idlePeriod = (float)$args['idle'];
        }

        $this->factory = new Factory($loop, $connector);
        $this->uri = $uri;
        $this->loop = $loop ?: Loop::get();
    }

    private function connecting()
    {
        if ($this->connecting !== null) {
            return $this->connecting;
        }

        // force-close connection if still waiting for previous disconnection
        if ($this->disconnecting !== null) {
            $this->disconnecting->close();
            $this->disconnecting = null;
        }

        $this->connecting = $connecting = $this->factory->createConnection($this->uri);
        $this->connecting->then(function (Connection $connection) {
            // connection completed => remember only until closed
            $connection->on('close', function () {
                $this->connecting = null;

                if ($this->idleTimer !== null) {
                    $this->loop->cancelTimer($this->idleTimer);
                    $this->idleTimer = null;
                }
            });
        }, function () {
            // connection failed => discard connection attempt
            $this->connecting = null;
        });

        return $connecting;
    }

    private function awake()
    {
        ++$this->pending;

        if ($this->idleTimer !== null) {
            $this->loop->cancelTimer($this->idleTimer);
            $this->idleTimer = null;
        }
    }

    private function idle()
    {
        --$this->pending;

        if ($this->pending < 1 && $this->idlePeriod >= 0 && $this->connecting !== null) {
            $this->idleTimer = $this->loop->addTimer($this->idlePeriod, function () {
                $this->connecting->then(function (Connection $connection) {
                    $this->disconnecting = $connection;
                    $connection->quit()->then(
                        function () {
                            // successfully disconnected => remove reference
                            $this->disconnecting = null;
                        },
                        function () {
                            // soft-close failed but will close anyway => remove reference
                            $this->disconnecting = null;
                        }
                    );
                });
                $this->connecting = null;
                $this->idleTimer = null;
            });
        }
    }

    /**
     * Performs an async query.
     *
     * This method returns a promise that will resolve with a `MysqlResult` on
     * success or will reject with an `Exception` on error. The MySQL protocol
     * is inherently sequential, so that all queries will be performed in order
     * and outstanding queries will be put into a queue to be executed once the
     * previous queries are completed.
     *
     * ```php
     * $mysql->query('CREATE TABLE test ...');
     * $mysql->query('INSERT INTO test (id) VALUES (1)');
     * ```
     *
     * If this SQL statement returns a result set (such as from a `SELECT`
     * statement), this method will buffer everything in memory until the result
     * set is completed and will then resolve the resulting promise. This is
     * the preferred method if you know your result set to not exceed a few
     * dozens or hundreds of rows. If the size of your result set is either
     * unknown or known to be too large to fit into memory, you should use the
     * [`queryStream()`](#querystream) method instead.
     *
     * ```php
     * $mysql->query($query)->then(function (React\Mysql\MysqlResult $command) {
     *     if (isset($command->resultRows)) {
     *         // this is a response to a SELECT etc. with some rows (0+)
     *         print_r($command->resultFields);
     *         print_r($command->resultRows);
     *         echo count($command->resultRows) . ' row(s) in set' . PHP_EOL;
     *     } else {
     *         // this is an OK message in response to an UPDATE etc.
     *         if ($command->insertId !== 0) {
     *             var_dump('last insert ID', $command->insertId);
     *         }
     *         echo 'Query OK, ' . $command->affectedRows . ' row(s) affected' . PHP_EOL;
     *     }
     * }, function (Exception $error) {
     *     // the query was not executed successfully
     *     echo 'Error: ' . $error->getMessage() . PHP_EOL;
     * });
     * ```
     *
     * You can optionally pass an array of `$params` that will be bound to the
     * query like this:
     *
     * ```php
     * $mysql->query('SELECT * FROM user WHERE id > ?', [$id]);
     * ```
     *
     * The given `$sql` parameter MUST contain a single statement. Support
     * for multiple statements is disabled for security reasons because it
     * could allow for possible SQL injection attacks and this API is not
     * suited for exposing multiple possible results.
     *
     * @param string $sql    SQL statement
     * @param array  $params Parameters which should be bound to query
     * @return PromiseInterface<MysqlResult>
     *     Resolves with a `MysqlResult` on success or rejects with an `Exception` on error.
     */
    public function query($sql, array $params = [])
    {
        if ($this->closed) {
            return \React\Promise\reject(new Exception('Connection closed'));
        }

        return $this->connecting()->then(function (Connection $connection) use ($sql, $params) {
            $this->awake();
            return $connection->query($sql, $params)->then(
                function (MysqlResult $result) {
                    $this->idle();
                    return $result;
                },
                function (\Exception $e) {
                    $this->idle();
                    throw $e;
                }
            );
        });
    }

    /**
     * Performs an async query and streams the rows of the result set.
     *
     * This method returns a readable stream that will emit each row of the
     * result set as a `data` event. It will only buffer data to complete a
     * single row in memory and will not store the whole result set. This allows
     * you to process result sets of unlimited size that would not otherwise fit
     * into memory. If you know your result set to not exceed a few dozens or
     * hundreds of rows, you may want to use the [`query()`](#query) method instead.
     *
     * ```php
     * $stream = $mysql->queryStream('SELECT * FROM user');
     * $stream->on('data', function ($row) {
     *     echo $row['name'] . PHP_EOL;
     * });
     * $stream->on('end', function () {
     *     echo 'Completed.';
     * });
     * ```
     *
     * You can optionally pass an array of `$params` that will be bound to the
     * query like this:
     *
     * ```php
     * $stream = $mysql->queryStream('SELECT * FROM user WHERE id > ?', [$id]);
     * ```
     *
     * This method is specifically designed for queries that return a result set
     * (such as from a `SELECT` or `EXPLAIN` statement). Queries that do not
     * return a result set (such as a `UPDATE` or `INSERT` statement) will not
     * emit any `data` events.
     *
     * See also [`ReadableStreamInterface`](https://github.com/reactphp/stream#readablestreaminterface)
     * for more details about how readable streams can be used in ReactPHP. For
     * example, you can also use its `pipe()` method to forward the result set
     * rows to a [`WritableStreamInterface`](https://github.com/reactphp/stream#writablestreaminterface)
     * like this:
     *
     * ```php
     * $mysql->queryStream('SELECT * FROM user')->pipe($formatter)->pipe($logger);
     * ```
     *
     * Note that as per the underlying stream definition, calling `pause()` and
     * `resume()` on this stream is advisory-only, i.e. the stream MAY continue
     * emitting some data until the underlying network buffer is drained. Also
     * notice that the server side limits how long a connection is allowed to be
     * in a state that has outgoing data. Special care should be taken to ensure
     * the stream is resumed in time. This implies that using `pipe()` with a
     * slow destination stream may cause the connection to abort after a while.
     *
     * The given `$sql` parameter MUST contain a single statement. Support
     * for multiple statements is disabled for security reasons because it
     * could allow for possible SQL injection attacks and this API is not
     * suited for exposing multiple possible results.
     *
     * @param string $sql    SQL statement
     * @param array  $params Parameters which should be bound to query
     * @return ReadableStreamInterface
     */
    public function queryStream($sql, $params = [])
    {
        if ($this->closed) {
            throw new Exception('Connection closed');
        }

        return \React\Promise\Stream\unwrapReadable(
            $this->connecting()->then(function (Connection $connection) use ($sql, $params) {
                $stream = $connection->queryStream($sql, $params);

                $this->awake();
                $stream->on('close', function () {
                    $this->idle();
                });

                return $stream;
            })
        );
    }

    /**
     * Checks that the connection is alive.
     *
     * This method returns a promise that will resolve (with a void value) on
     * success or will reject with an `Exception` on error. The MySQL protocol
     * is inherently sequential, so that all commands will be performed in order
     * and outstanding command will be put into a queue to be executed once the
     * previous queries are completed.
     *
     * ```php
     * $mysql->ping()->then(function () {
     *     echo 'OK' . PHP_EOL;
     * }, function (Exception $e) {
     *     echo 'Error: ' . $e->getMessage() . PHP_EOL;
     * });
     * ```
     *
     * @return PromiseInterface<void>
     *     Resolves with a `void` value on success or rejects with an `Exception` on error.
     */
    public function ping()
    {
        if ($this->closed) {
            return \React\Promise\reject(new Exception('Connection closed'));
        }

        return $this->connecting()->then(function (Connection $connection) {
            $this->awake();
            return $connection->ping()->then(
                function () {
                    $this->idle();
                },
                function (\Exception $e) {
                    $this->idle();
                    throw $e;
                }
            );
        });
    }

    /**
     * Quits (soft-close) the connection.
     *
     * This method returns a promise that will resolve (with a void value) on
     * success or will reject with an `Exception` on error. The MySQL protocol
     * is inherently sequential, so that all commands will be performed in order
     * and outstanding commands will be put into a queue to be executed once the
     * previous commands are completed.
     *
     * ```php
     * $mysql->query('CREATE TABLE test ...');
     * $mysql->quit();
     * ```
     *
     * This method will gracefully close the connection to the MySQL database
     * server once all outstanding commands are completed. See also
     * [`close()`](#close) if you want to force-close the connection without
     * waiting for any commands to complete instead.
     *
     * @return PromiseInterface<void>
     *     Resolves with a `void` value on success or rejects with an `Exception` on error.
     */
    public function quit()
    {
        if ($this->closed) {
            return \React\Promise\reject(new Exception('Connection closed'));
        }

        // not already connecting => no need to connect, simply close virtual connection
        if ($this->connecting === null) {
            $this->close();
            return \React\Promise\resolve(null);
        }

        return new Promise(function (callable $resolve, callable $reject) {
            $this->connecting()->then(function (Connection $connection) use ($resolve, $reject) {
                $this->awake();
                // soft-close connection and emit close event afterwards both on success or on error
                $connection->quit()->then(
                    function () use ($resolve){
                        $resolve(null);
                        $this->close();
                    },
                    function (\Exception $e) use ($reject) {
                        $reject($e);
                        $this->close();
                    }
                );
            }, function (\Exception $e) use ($reject) {
                // emit close event afterwards when no connection can be established
                $reject($e);
                $this->close();
            });
        });
    }

    /**
     * Force-close the connection.
     *
     * Unlike the `quit()` method, this method will immediately force-close the
     * connection and reject all outstanding commands.
     *
     * ```php
     * $mysql->close();
     * ```
     *
     * Forcefully closing the connection will yield a warning in the server logs
     * and should generally only be used as a last resort. See also
     * [`quit()`](#quit) as a safe alternative.
     *
     * @return void
     */
    public function close()
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        // force-close connection if still waiting for previous disconnection
        if ($this->disconnecting !== null) {
            $this->disconnecting->close();
            $this->disconnecting = null;
        }

        // either close active connection or cancel pending connection attempt
        if ($this->connecting !== null) {
            $this->connecting->then(function (Connection $connection) {
                $connection->close();
            }, function () {
                // ignore to avoid reporting unhandled rejection
            });
            if ($this->connecting !== null) {
                $this->connecting->cancel();
                $this->connecting = null;
            }
        }

        if ($this->idleTimer !== null) {
            $this->loop->cancelTimer($this->idleTimer);
            $this->idleTimer = null;
        }

        $this->emit('close');
        $this->removeAllListeners();
    }
}
