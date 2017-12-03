<?php

namespace React\MySQL\Pool;

use React\MySQL\Commands\QueryCommand;

/**
 * Class PoolQueryResult
 *
 * Represent result passed in resolved promise from pool query method.
 *
 * @package React\MySQL\Pool
 *
 * @see \React\MySQL\Pool\PoolInterface::query()
 */
class PoolQueryResult
{

    /**
     * @var PoolInterface
     */
    private $pool;

    /**
     * @var QueryCommand
     */
    private $cmd;

    /**
     * PoolQueryResult constructor.
     *
     * @param PoolInterface $pool A pool from which we got this result.
     * @param QueryCommand  $cmd  A query command as is.
     */
    public function __construct(PoolInterface $pool, QueryCommand $cmd)
    {
        $this->pool = $pool;
        $this->cmd = $cmd;
    }

    /**
     * Get pool from which we got this result.
     *
     * @return PoolInterface
     */
    public function getPool()
    {
        return $this->pool;
    }

    /**
     * Get executed QueryCommand instance.
     *
     * @return QueryCommand
     */
    public function getCmd()
    {
        return $this->cmd;
    }
}