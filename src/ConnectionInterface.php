<?php

namespace React\MySQL;

use React\MySQL\Commands\QueryCommand;

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
     * Do a async query.
     *
     * @param string        $sql        MySQL sql statement.
     * @param callable|null $callback   Query result handler callback.
     * @param mixed         $params,... Parameters which should bind to query.
     *
     * $callback signature:
     *
     *  function (QueryCommand $cmd, ConnectionInterface $conn): void
     *
     * The given `$sql` parameter MUST contain a single statement. Support
     * for multiple statements is disabled for security reasons because it
     * could allow for possible SQL injection attacks and this API is not
     * suited for exposing multiple possible results.
     *
     * @return QueryCommand|null Return QueryCommand if $callback not specified.
     * @throws Exception if the connection is not initialized or already closed/closing
     */
    public function query($sql, $callback = null, $params = null);

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
     * Select specified database.
     *
     * @param string $dbname Database name.
     *
     * @return QueryCommand
     * @throws Exception if the connection is not initialized or already closed/closing
     */
    public function selectDb($dbname);

    /**
     * @return mixed
     */
    public function listFields();

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
