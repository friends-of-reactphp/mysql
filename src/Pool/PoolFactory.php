<?php

namespace React\MySQL\Pool;

use React\EventLoop\LoopInterface;
use React\MySQL\Connection;

/**
 * Class PoolFactory
 *
 * @package React\MySQL\Pool
 */
class PoolFactory
{

    /**
     * Create new pool.
     *
     * @param LoopInterface $loop           A LoopInterface instance.
     * @param array         $connectOptions MySQL connection options.
     * @param integer       $count          Number of created connections.
     *
     * @return PoolInterface
     *
     * @throws \InvalidArgumentException Got invalid options.
     */
    public static function createPool(
        LoopInterface $loop,
        array $connectOptions,
        $count
    ) {
        if (! is_numeric($count)) {
            throw new \InvalidArgumentException(sprintf(
                '$count should be \'integer\' but \'%s\' given',
                is_object($count) ? get_class($count) : gettype($count)
            ));
        }
        $count = (int) $count;

        if ($count <= 0) {
            throw new \InvalidArgumentException(sprintf(
                '$count should be greater then 0 but %d given',
                $count
            ));
        }

        //
        // Create specified number of connection but not connect 'cause we do it
        // then somebody request connection from pool or make query to pool.
        //
        $connections = [];
        for ($i = 0; $i < $count; $i++) {
            $connections[] = new Connection($loop, $connectOptions);
        }

        return new Pool($connections);
    }
}
