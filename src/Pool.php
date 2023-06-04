<?php 

namespace React\MySQL;

use React\MySQL\Factory;
use React\EventLoop\LoopInterface;
use React\Socket\ConnectorInterface;
use React\Promise\Deferred;
use React\MySQL\ConnectionInterface;
use React\MySQL\QueryResult;
use React\EventLoop\Loop;
use React\Promise\Timer\TimeoutException;

class Pool
{
    private $max_connections;
    private $max_wait_queue;
    private $current_connections = 0;
    private $wait_timeout = 0;
    private $idle_connections = [];
    private $wait_queue;
    private $loop;
    private $factory;
    private $uri;


    public function __construct(
        $uri,
        $config = [],
        LoopInterface $loop = null,
        ConnectorInterface $connector = null
    )
    {
        $this->uri = $uri;
        $this->max_connections = $config['max_connections'] ?? 10;
        $this->max_wait_queue = $config['max_wait_queue'] ?? 50;
        $this->wait_timeout = $config['wait_timeout'] ?? 0;
        $this->wait_queue = new \SplObjectStorage;
        $this->loop = $loop ?: Loop::get();
        $this->factory = new Factory($loop, $connector);;
    }

    public function query($sql, array $params = [])
    {
        
        $that = $this;
        $deferred = new Deferred();

        $this->getIdleConnection()->then(function (ConnectionInterface $connection) use ($sql, $params, $deferred, $that) {
            $connection->query($sql, $params)->then(function(QueryResult $command) use ($deferred, $connection, $that) {
                try {
                    $deferred->resolve($command);
                } catch (\Throwable $th) {
                    //todo handle $th
                }
                $that->releaseConnection($connection);
            }, function (\Exception $e) use ($deferred, $connection, $that) {
                $deferred->reject($e);
                
                $connection->ping()->then(function () use ($connection, $that) {
                    $that->releaseConnection($connection);
                }, function (\Exception $e) use ($that) {
                    $that->current_connections--;
                });
            });
        }, function (\Exception $e) use ($deferred) {
            $deferred->reject($e);
        });

        return $deferred->promise();

    }
    public function queryStream($sql, array $params = [])
    {   

        $that = $this;
        $error = null;

        $stream = \React\Promise\Stream\unwrapReadable(
            $this->getIdleConnection()->then(function (ConnectionInterface $connection) use ($sql, $params, $that) {
                $stream = $connection->queryStream($sql, $params);
                $stream->on('end', function () use ($connection, $that) {
                    $that->releaseConnection($connection);
                });
                $stream->on('error', function ($err) use ($connection, $that) {
                    $connection->ping()->then(function () use ($connection, $that) {
                        $that->releaseConnection($connection);
                    }, function (\Exception $e) use ($that) {
                        $that->current_connections--;
                    });
                });
                return $stream;
            }, function (\Exception $e) use (&$error) {
                $error = $e;
                throw $e;
            })
        );

        if ($error) {
            \React\EventLoop\Loop::addTimer(0.0001, function() use ($stream, $error) {
               $stream->emit('error', [$error]);
            });
        }

        return $stream;

    }

    public function getIdleConnection()
    {
        if ($this->idle_connections) {
            $connection = array_shift($this->idle_connections);
            return \React\Promise\resolve($connection);
        }

        if ($this->current_connections < $this->max_connections) {
            $this->current_connections++;
            return \React\Promise\resolve($this->factory->createLazyConnection($this->uri));
        }

        if ($this->max_wait_queue && $this->wait_queue->count() >= $this->max_wait_queue) {
            return \React\Promise\reject(new \Exception("over max_wait_queue: ". $this->max_wait_queue.'-current quueue:'.$this->wait_queue->count()));
        }

        $deferred = new Deferred();
        $this->wait_queue->attach($deferred);

        if (!$this->wait_timeout) {
            return $deferred->promise();
        }
        
        $that = $this;

        return \React\Promise\Timer\timeout($deferred->promise(), $this->wait_timeout, $this->loop)->then(null, function ($e) use ($that, $deferred) {
            
            $that->wait_queue->detach($deferred);

            if ($e instanceof TimeoutException) {
                throw new \RuntimeException(
                    'wait timed out after ' . $e->getTimeout() . ' seconds (ETIMEDOUT)'. 'and wait queue '.$that->wait_queue->count().' count',
                    \defined('SOCKET_ETIMEDOUT') ? \SOCKET_ETIMEDOUT : 110
                );
            }
            throw $e;
        });
    }

    public function releaseConnection(ConnectionInterface $connection)
    {
        if ($this->wait_queue->count()>0) {
            $deferred = $this->wait_queue->current();
            $deferred->resolve($connection);
            $this->wait_queue->detach($deferred);
            return;
        }

        $this->idle_connections[] = $connection;
    }
}
