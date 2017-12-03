<?php

namespace React\MySQL\Pool;

use React\MySQL\ConnectionInterface;
use React\Promise\Deferred;

/**
 * Class Pool
 *
 * @package React\MySQL\Pool
 */
class Pool extends AbstractPool
{

    /**
     * @var \SplObjectStorage|ConnectionInterface[]
     */
    private $connections;

    /**
     * Index of current connection in pool.
     *
     * @var integer
     */
    private $index = 0;

    /**
     * Pool constructor.
     *
     * @param ConnectionInterface[] $connections Array of MySQL connection.
     */
    public function __construct(array $connections)
    {
        if (count($connections) === 0) {
            throw new \InvalidArgumentException('Should be at least one connection in pool');
        }

        $this->connections = new \SplObjectStorage();

        foreach ($connections as $connection) {
            if (! $connection instanceof \React\MySQL\ConnectionInterface) {
                throw new \InvalidArgumentException(sprintf(
                    'Each passed connection should implements \'%s\' but one of connections is \'%s\'',
                    'React\MySQL\ConnectionInterface',
                    is_object($connection) ? get_class($connection) : gettype($connection)
                ));
            }
            $this->connections->attach($connection);
        }

        $this->connections->rewind();
    }

    /**
     * {@inheritDoc}
     */
    public function getConnection()
    {
        $connection = $this->roundRobin();

        //
        // Connect if current connection is not initialized or already closed by
        // some reason.
        //
        if (($connection->getState() === ConnectionInterface::STATE_INIT)
            || ($connection->getState() === ConnectionInterface::STATE_CLOSED)) {
            $deferred = new Deferred();

            $connection->connect(function (\Exception $exception = null, ConnectionInterface $connection) use ($deferred) {
                if ($exception !== null) {
                    $deferred->reject($exception);
                } else {
                    $deferred->resolve($connection);
                }
            });

            return $deferred->promise();
        }

        return \React\Promise\resolve($connection);
    }

    /**
     * Get connection from pool by RR algorithm.
     *
     * @return ConnectionInterface
     *
     * @link https://en.wikipedia.org/wiki/Round-robin_scheduling
     */
    private function roundRobin()
    {
        if ($this->index === count($this->connections)) {
            $this->connections->rewind();
            $this->index = 0;
        }

        $connection = $this->connections->current();
        $this->index++;
        $this->connections->next();

        return $connection;
    }

    /**
     * {@inheritDoc}
     */
    public function count()
    {
        return count($this->connections);
    }
}
