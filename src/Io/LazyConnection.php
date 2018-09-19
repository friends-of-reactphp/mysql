<?php

namespace React\MySQL\Io;

use React\MySQL\ConnectionInterface;
use Evenement\EventEmitter;
use React\Promise\PromiseInterface;
use React\MySQL\Exception;

/**
 * @internal
 * @see \React\MySQL\Factory::createLazyConnection()
 */
class LazyConnection extends EventEmitter implements ConnectionInterface
{
    private $connecting;
    private $closed = false;
    private $busy = false;

    public function __construct(PromiseInterface $connecting)
    {
        $this->connecting = $connecting;

        $connecting->then(function (ConnectionInterface $connection) {
            // connection completed => forward error and close events
            $connection->on('error', function ($e) {
                $this->emit('error', [$e]);
            });
            $connection->on('close', function () {
                $this->close();
            });
        }, function (\Exception $e) {
            // connection failed => emit error if connection is not already closed
            if ($this->closed) {
                return;
            }

            $this->emit('error', [$e]);
            $this->close();
        });
    }

    public function query($sql, array $params = [])
    {
        if ($this->connecting === null) {
            return \React\Promise\reject(new Exception('Connection closed'));
        }

        return $this->connecting->then(function (ConnectionInterface $connection) use ($sql, $params) {
            return $connection->query($sql, $params);
        });
    }

    public function queryStream($sql, $params = [])
    {
        if ($this->connecting === null) {
            throw new Exception('Connection closed');
        }

        return \React\Promise\Stream\unwrapReadable(
            $this->connecting->then(function (ConnectionInterface $connection) use ($sql, $params) {
                return $connection->queryStream($sql, $params);
            })
        );
    }

    public function ping()
    {
        if ($this->connecting === null) {
            return \React\Promise\reject(new Exception('Connection closed'));
        }

        return $this->connecting->then(function (ConnectionInterface $connection) {
            return $connection->ping();
        });
    }

    public function quit()
    {
        if ($this->connecting === null) {
            return \React\Promise\reject(new Exception('Connection closed'));
        }

        return $this->connecting->then(function (ConnectionInterface $connection) {
            return $connection->quit();
        });
    }

    public function close()
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        // either close active connection or cancel pending connection attempt
        $this->connecting->then(function (ConnectionInterface $connection) {
            $connection->close();
        });
        $this->connecting->cancel();

        $this->connecting = null;

        $this->emit('close');
        $this->removeAllListeners();
    }
}
