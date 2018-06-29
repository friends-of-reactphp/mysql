<?php

namespace React\MySQL;

use React\Promise\PromiseInterface;
use React\Stream\ReadableStreamInterface;

/**
 * Interface ConnectionInterface
 *
 * @package React\MySQL
 */
interface ConnectionInterface
{
    const STATE_INIT                = 0;
    const STATE_CONNECT_FAILED      = 1;
    const STATE_AUTHENTICATE_FAILED = 2;
    const STATE_CONNECTING          = 3;
    const STATE_CONNECTED           = 4;
    const STATE_AUTHENTICATED       = 5;
    const STATE_CLOSEING            = 6;
    const STATE_CLOSED              = 7;

    /**
     * Performs an async query.
     *
     * This method returns a promise that will resolve with a `QueryResult` on
     * success or will reject with an `Exception` on error. The MySQL protocol
     * is inherently sequential, so that all queries will be performed in order
     * and outstanding queries will be put into a queue to be executed once the
     * previous queries are completed.
     *
     * ```php
     * $connection->query('CREATE TABLE test ...');
     * $connection->query('INSERT INTO test (id) VALUES (1)');
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
     * $connection->query($query)->then(function (QueryResult $command) {
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
     * $connection->query('SELECT * FROM user WHERE id > ?', [$id]);
     * ```
     *
     * The given `$sql` parameter MUST contain a single statement. Support
     * for multiple statements is disabled for security reasons because it
     * could allow for possible SQL injection attacks and this API is not
     * suited for exposing multiple possible results.
     *
     * @param string $sql    SQL statement
     * @param array  $params Parameters which should be bound to query
     * @return PromiseInterface Returns a Promise<QueryResult,Exception>
     */
    public function query($sql, array $params = array());

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
     * $stream = $connection->queryStream('SELECT * FROM user');
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
     * $stream = $connection->queryStream('SELECT * FROM user WHERE id > ?', [$id]);
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
     * $connection->queryStream('SELECT * FROM user')->pipe($formatter)->pipe($logger);
     * ```
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
    public function queryStream($sql, $params = array());

    /**
     * Checks that connection is alive.
     *
     * @param callable $callback Checking result handler.
     *
     * $callback signature:
     *
     *  function (\Exception $e = null, ConnectionInterface $conn): void
     *
     * @return void
     * @throws Exception if the connection is not initialized or already closed/closing
     */
    public function ping($callback);

    /**
     * Change connection option parameter.
     *
     * @param string $name  Parameter name.
     * @param mixed  $value New value.
     *
     * @return ConnectionInterface
     */
    public function setOption($name, $value);

    /**
     * Get connection parameter value.
     *
     * @param string $name    Parameter which should be returned.
     * @param mixed  $default Value which should be returned if parameter is not
     *                        set.
     *
     * @return mixed
     */
    public function getOption($name, $default = null);

    /**
     * Information about the server with which the connection is established.
     *
     * Available:
     *
     *  * serverVersion
     *  * threadId
     *  * ServerCaps
     *  * serverLang
     *  * serverStatus
     *
     * @return array
     */
    public function getServerOptions();

    /**
     * Get connection state.
     *
     * @return integer
     *
     * @see ConnectionInterface::STATE_INIT
     * @see ConnectionInterface::STATE_CONNECT_FAILED
     * @see ConnectionInterface::STATE_AUTHENTICATE_FAILED
     * @see ConnectionInterface::STATE_CONNECTING
     * @see ConnectionInterface::STATE_CONNECTED
     * @see ConnectionInterface::STATE_AUTHENTICATED
     * @see ConnectionInterface::STATE_CLOSEING
     * @see ConnectionInterface::STATE_CLOSED
     */
    public function getState();

    /**
     * Close the connection.
     *
     * @param callable|null $callback A callback which should be run after
     *                                connection successfully closed.
     *
     * $callback signature:
     *
     *  function (ConnectionInterface $conn): void
     *
     * @return void
     * @throws Exception if the connection is not initialized or already closed/closing
     */
    public function close($callback = null);

    /**
     * Connect to mysql server.
     *
     * @param callable $callback Connection result handler.
     *
     * $callback signature:
     *
     *  function (\Exception $e = null, ConnectionInterface $conn): void
     *
     * This method should be invoked once after the `Connection` is initialized.
     * You can queue additional `query()`, `ping()` and `close()` calls after
     * invoking this method without having to await its resolution first.
     *
     * @return void
     * @throws Exception if the connection is already initialized, i.e. it MUST NOT be called more than once.
     */
    public function connect($callback);
}
