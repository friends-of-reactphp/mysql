<?php

namespace React\MySQL\Io;

use React\MySQL\ConnectionInterface;
use Evenement\EventEmitter;
use React\MySQL\Exception;
use React\MySQL\Factory;
use React\EventLoop\LoopInterface;
use React\MySQL\QueryResult;

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

    /**
     * @var ConnectionInterface|null
     */
    private $disconnecting;

    private $loop;
    private $idlePeriod = 60.0;
    private $idleTimer;
    private $pending = 0;

    public function __construct(Factory $factory, $uri, LoopInterface $loop)
    {
        $args = array();
        \parse_str(\parse_url($uri, \PHP_URL_QUERY), $args);
        if (isset($args['idle'])) {
            $this->idlePeriod = (float)$args['idle'];
        }

        $this->factory = $factory;
        $this->uri = $uri;
        $this->loop = $loop;
    }

    private function connecting()
    {
        if ($this->connecting !== null) {
            return $this->connecting;
        }

        // force-close connection if still waiting for previous disconnection
        if ($this->disconnecting !== null) {
            $this->disconnecting->close();
            $this->disconnecting = null;
        }

        $this->connecting = $connecting = $this->factory->createConnection($this->uri);
        $this->connecting->then(function (ConnectionInterface $connection) {
            // connection completed => remember only until closed
            $connection->on('close', function () {
                $this->connecting = null;

                if ($this->idleTimer !== null) {
                    $this->loop->cancelTimer($this->idleTimer);
                    $this->idleTimer = null;
                }
            });
        }, function () {
            // connection failed => discard connection attempt
            $this->connecting = null;
        });

        return $connecting;
    }

    private function awake()
    {
        ++$this->pending;

        if ($this->idleTimer !== null) {
            $this->loop->cancelTimer($this->idleTimer);
            $this->idleTimer = null;
        }
    }

    private function idle()
    {
        --$this->pending;

        if ($this->pending < 1 && $this->idlePeriod >= 0 && $this->connecting !== null) {
            $this->idleTimer = $this->loop->addTimer($this->idlePeriod, function () {
                $this->connecting->then(function (ConnectionInterface $connection) {
                    $this->disconnecting = $connection;
                    $connection->quit()->then(
                        function () {
                            // successfully disconnected => remove reference
                            $this->disconnecting = null;
                        },
                        function () use ($connection) {
                            // soft-close failed => force-close connection
                            $connection->close();
                            $this->disconnecting = null;
                        }
                    );
                });
                $this->connecting = null;
                $this->idleTimer = null;
            });
        }
    }

    public function query($sql, array $params = [])
    {
        if ($this->closed) {
            return \React\Promise\reject(new Exception('Connection closed'));
        }

        return $this->connecting()->then(function (ConnectionInterface $connection) use ($sql, $params) {
            $this->awake();
            return $connection->query($sql, $params)->then(
                function (QueryResult $result) {
                    $this->idle();
                    return $result;
                },
                function (\Exception $e) {
                    $this->idle();
                    throw $e;
                }
            );
        });
    }

    public function queryStream($sql, $params = [])
    {
        if ($this->closed) {
            throw new Exception('Connection closed');
        }

        return \React\Promise\Stream\unwrapReadable(
            $this->connecting()->then(function (ConnectionInterface $connection) use ($sql, $params) {
                $stream = $connection->queryStream($sql, $params);

                $this->awake();
                $stream->on('close', function () {
                    $this->idle();
                });

                return $stream;
            })
        );
    }

    public function ping()
    {
        if ($this->closed) {
            return \React\Promise\reject(new Exception('Connection closed'));
        }

        return $this->connecting()->then(function (ConnectionInterface $connection) {
            $this->awake();
            return $connection->ping()->then(
                function () {
                    $this->idle();
                },
                function (\Exception $e) {
                    $this->idle();
                    throw $e;
                }
            );
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
            $this->awake();
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

        // force-close connection if still waiting for previous disconnection
        if ($this->disconnecting !== null) {
            $this->disconnecting->close();
            $this->disconnecting = null;
        }

        // either close active connection or cancel pending connection attempt
        if ($this->connecting !== null) {
            $this->connecting->then(function (ConnectionInterface $connection) {
                $connection->close();
            });
            if ($this->connecting !== null) {
                $this->connecting->cancel();
                $this->connecting = null;
            }
        }

        if ($this->idleTimer !== null) {
            $this->loop->cancelTimer($this->idleTimer);
            $this->idleTimer = null;
        }

        $this->emit('close');
        $this->removeAllListeners();
    }
}
