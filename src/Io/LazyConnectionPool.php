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

    public function query($sql, array $params = array()): \React\Promise\PromiseInterface
    {
        $pointer = $this->shiftPoolPointer();
        $this->requestCounter[$pointer]++;
        return $this->pool[$pointer]->query($sql, $params)->then(function ($result) use ($pointer) {
            $this->requestCounter[$pointer]--;
            return $result;
        });
    }

    public function queryStream($sql, $params = array()): \React\Stream\ReadableStreamInterface
    {
        $pointer = $this->shiftPoolPointer();
        $this->requestCounter[$pointer]++;
        return $this->pool[$pointer]->queryStream($sql, $params)->then(function ($result) use ($pointer) {
            $this->requestCounter[$pointer]--;
            return $result;
        });
    }

    public function ping(): \React\Promise\PromiseInterface
    {
        $pointer = $this->shiftPoolPointer();
        $this->requestCounter[$pointer]++;
        return $this->pool[$pointer]->ping()->then(function ($result) use ($pointer) {
            $this->requestCounter[$pointer]--;
            return $result;
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
