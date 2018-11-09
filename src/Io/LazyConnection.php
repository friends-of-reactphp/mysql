<?php

namespace React\MySQL\Io;

use React\MySQL\ConnectionInterface;
use Evenement\EventEmitter;
use React\MySQL\Exception;
use React\MySQL\Factory;

/**
 * @internal
 * @see \React\MySQL\Factory::createLazyConnection()
 */
class LazyConnection extends EventEmitter implements ConnectionInterface
{
    private $factory;
    private $uri;
    private $connecting;
    private $closed = false;
    private $busy = false;

    public function __construct(Factory $factory, $uri)
    {
        $this->factory = $factory;
        $this->uri = $uri;
    }

    private function connecting()
    {
        if ($this->connecting !== null) {
            return $this->connecting;
        }

        $this->connecting = $connecting = $this->factory->createConnection($this->uri);
        $this->connecting->then(function (ConnectionInterface $connection) {
            // connection completed => remember only until closed
            $connection->on('close', function () {
                $this->connecting = null;
            });
        }, function () {
            // connection failed => discard connection attempt
            $this->connecting = null;
        });

        return $connecting;
    }

    public function query($sql, array $params = [])
    {
        if ($this->closed) {
            return \React\Promise\reject(new Exception('Connection closed'));
        }

        return $this->connecting()->then(function (ConnectionInterface $connection) use ($sql, $params) {
            return $connection->query($sql, $params);
        });
    }

    public function queryStream($sql, $params = [])
    {
        if ($this->closed) {
            throw new Exception('Connection closed');
        }

        return \React\Promise\Stream\unwrapReadable(
            $this->connecting()->then(function (ConnectionInterface $connection) use ($sql, $params) {
                return $connection->queryStream($sql, $params);
            })
        );
    }

    public function ping()
    {
        if ($this->closed) {
            return \React\Promise\reject(new Exception('Connection closed'));
        }

        return $this->connecting()->then(function (ConnectionInterface $connection) {
            return $connection->ping();
        });
    }

    public function quit()
    {
        if ($this->closed) {
            return \React\Promise\reject(new Exception('Connection closed'));
        }

        // not already connecting => no need to connect, simply close virtual connection
        if ($this->connecting === null) {
            $this->close();
            return \React\Promise\resolve();
        }

        return $this->connecting()->then(function (ConnectionInterface $connection) {
            return $connection->quit()->then(
                function () {
                    $this->close();
                },
                function (\Exception $e) {
                    $this->close();
                    throw $e;
                }
            );
        });
    }

    public function close()
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        // either close active connection or cancel pending connection attempt
        if ($this->connecting !== null) {
            $this->connecting->then(function (ConnectionInterface $connection) {
                $connection->close();
            });
            $this->connecting->cancel();
            $this->connecting = null;
        }

        $this->emit('close');
        $this->removeAllListeners();
    }
}
