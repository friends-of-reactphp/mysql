<?php

namespace React\MySQL\Pool;

use React\Promise\Promise;

/**
 * Interface PoolInterface
 *
 * MySQL connection pool.
 *
 * @package React\MySQL\Pool
 */
interface PoolInterface extends \Countable
{

    /**
     * Do a async query.
     *
     * The query is performed on one particular connection from the pool.
     *
     * ```php
     * $pool
     *  ->query('SELECT * FROM `table`')
     *  ->then(function (PoolQueryResult $result) { ... })
     *  ->otherwise(function (\Exception $exception) { ... })
     * ```
     *
     * @param string $sql        MySQL sql statement.
     * @param mixed  $params,... Parameters which should bind to query.
     *
     * @return Promise
     *
     * @see \React\MySQL\Pool\PoolQueryResult
     */
    public function query($sql, $params = null);

    /**
     * Get next connection to MySQL server.
     *
     * Each concrete pool may use different algorithm for selecting connections.
     *
     * ```php
     * $pool
     *  ->getConnection()
     *  ->then(function (ConnectionInterface $connection) { ... })
     *  ->otherwise(function (\Exception $exception) { ... })
     * ```
     *
     * @return Promise
     */
    public function getConnection();
}
