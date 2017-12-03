<?php

namespace React\MySQL\Pool;

use React\MySQL\Commands\QueryCommand;
use React\MySQL\ConnectionInterface;
use React\Promise\Deferred;

/**
 * Class AbstractPool
 *
 * Base class for pools.
 *
 * @package React\MySQL\Pool
 */
abstract class AbstractPool implements PoolInterface
{

    /**
     * {@inheritDoc}
     */
    public function query($sql, $params = null)
    {
        $params = func_get_args();
        array_shift($params); // Remove $sql parameter.

        return $this->getConnection()
            ->then(function (ConnectionInterface $connection) use ($sql, $params) {
                $deferred = new Deferred();

                $callback = function (QueryCommand $command) use ($deferred) {
                    if ($command->getError() !== null) {
                        $deferred->reject($command);
                    } else {
                        $deferred->resolve(new PoolQueryResult($this, $command));
                    }
                };

                //
                // Inject callback into `query` method arguments.
                //
                $params = array_merge([ $sql, $callback ], $params);
                call_user_func_array([ $connection, 'query' ], $params);

                return $deferred->promise();
            });
    }
}
