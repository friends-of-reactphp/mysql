<?php
declare(strict_types=1);

namespace React\MySQL\Io;

use Evenement\EventEmitter;
use React\MySQL\ConnectionInterface;
use React\MySQL\Factory;
use React\Stream\Util;
use function React\Promise\resolve;

class LazyConnectionPool extends EventEmitter implements ConnectionInterface
{
    const CS_ROUND_ROBIN = 'round-robin';
    const CS_BY_LOAD = 'load';

    protected array $pool = [];
    protected int $poolSize;
    protected int $poolPointer = 0; // current connection in pool - RoundRobin
    protected array $requestCounter = []; // count requests per connection
    protected string $connectionSelector;

    public function __construct(Factory $factory, string $connectionURI, int $poolSize = 10, string $connectionSelector = self::CS_ROUND_ROBIN)
    {
        $this->connectionSelector = $connectionSelector;
        $this->poolSize = $poolSize;
        for ($i = 0; $i < $poolSize; $i++) {
            $this->pool[$i] = $connection = $factory->createLazyConnection($connectionURI);
            $this->requestCounter[$i] = 0;
            Util::forwardEvents($connection, $this, ['error', 'close']);
        }
    }

    /**
     * set the internal pool-pointer to the next valid connection on depending on the connectionSelector
     * @return int
     */
    protected function shiftPoolPointer(): int
    {
        switch ($this->connectionSelector) {
            case self::CS_ROUND_ROBIN:
                $this->poolPointer = ($this->poolPointer + 1) % $this->poolSize;
                break;
            case self::CS_BY_LOAD:
                $rcList = $this->requestCounter; // copy
                asort($rcList, SORT_NUMERIC);
                $this->poolPointer = key($rcList);
                break;
        }
        return $this->poolPointer;
    }

    /**
     * @param callable $callback received an ConnectionInterface as parameter
     * @return mixed
     */
    protected function pooledCallback(callable $callback)
    {
        $pointer = $this->shiftPoolPointer();
        $this->requestCounter[$pointer]++;
        $connection = $this->pool[$pointer];
        return $callback($connection)->then(function ($result) use ($pointer) {
            $this->requestCounter[$pointer]--;
            return $result;
        });
    }

    public function query($sql, array $params = array()): \React\Promise\PromiseInterface
    {
        return $this->pooledCallback(function (ConnectionInterface $connection) use ($sql, $params) {
            return $connection->query($sql, $params);
        });
    }

    public function queryStream($sql, $params = array()): \React\Stream\ReadableStreamInterface
    {
        return $this->pooledCallback(function (ConnectionInterface $connection) use ($sql, $params) {
            return $connection->queryStream($sql, $params);
        });
    }

    public function ping(): \React\Promise\PromiseInterface
    {
        return $this->pooledCallback(function (ConnectionInterface $connection) {
            return $connection->ping();
        });
    }

    public function quit(): \React\Promise\PromiseInterface
    {
        return resolve(array_map(function ($connection) {
            $connection->quit();
            return $connection;
        }, $this->pool));
    }

    public function close(): \React\Promise\PromiseInterface
    {
        return resolve(array_map(function ($connection) {
            $connection->close();
            return $connection;
        }, $this->pool));
    }
}
