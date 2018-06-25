<?php

namespace React\MySQL\Io;

use Evenement\EventEmitter;
use React\EventLoop\LoopInterface;
use React\MySQL\Commands\AbstractCommand;
use React\MySQL\Commands\AuthenticateCommand;
use React\MySQL\Commands\CommandInterface;
use React\MySQL\Commands\PingCommand;
use React\MySQL\Commands\QueryCommand;
use React\MySQL\Commands\QuitCommand;
use React\MySQL\ConnectionInterface;
use React\MySQL\Exception;
use React\MySQL\QueryResult;
use React\Promise\Deferred;
use React\Promise\Promise;
use React\Socket\ConnectionInterface as SocketConnectionInterface;
use React\Socket\Connector;
use React\Socket\ConnectorInterface;
use React\Stream\ThroughStream;

/**
 * @internal
 * @see ConnectionInterface
 */
class Connection extends EventEmitter implements ConnectionInterface
{
    /**
     * @var LoopInterface
     */
    private $loop;

    /**
     * @var Connector
     */
    private $connector;

    /**
     * @var array
     */
    private $options = [
        'host'   => '127.0.0.1',
        'port'   => 3306,
        'user'   => 'root',
        'passwd' => '',
        'dbname' => '',
    ];

    /**
     * @var array
     */
    private $serverOptions;

    /**
     * @var Executor
     */
    private $executor;

    /**
     * @var integer
     */
    private $state = self::STATE_INIT;

    /**
     * @var SocketConnectionInterface
     */
    private $stream;

    /**
     * @var Parser
     */
    public $parser;

    /**
     * Connection constructor.
     *
     * @param LoopInterface      $loop           ReactPHP event loop instance.
     * @param array              $connectOptions MySQL connection options.
     * @param ConnectorInterface $connector      (optional) socket connector instance.
     */
    public function __construct(LoopInterface $loop, array $connectOptions = array(), ConnectorInterface $connector = null)
    {
        $this->loop       = $loop;
        if (!$connector) {
            $connector    = new Connector($loop);
        }
        $this->connector  = $connector;
        $this->executor   = new Executor();
        $this->options    = $connectOptions + $this->options;
    }

    /**
     * {@inheritdoc}
     */
    public function query($sql, array $params = array())
    {
        $query = new Query($sql);
        if ($params) {
            $query->bindParamsFromArray($params);
        }

        $command = new QueryCommand($this);
        $command->setQuery($query);
        try {
            $this->_doCommand($command);
        } catch (\Exception $e) {
            return \React\Promise\reject($e);
        }

        $deferred = new Deferred();

        // store all result set rows until result set end
        $rows = array();
        $command->on('result', function ($row) use (&$rows) {
            $rows[] = $row;
        });
        $command->on('end', function ($command) use ($deferred, &$rows) {
            $result = new QueryResult();
            $result->resultFields = $command->resultFields;
            $result->resultRows = $rows;
            $rows = array();

            $deferred->resolve($result);
        });

        // resolve / reject status reply (response without result set)
        $command->on('error', function ($error) use ($deferred) {
            $deferred->reject($error);
        });
        $command->on('success', function (QueryCommand $command) use ($deferred) {
            $result = new QueryResult();
            $result->affectedRows = $command->affectedRows;
            $result->insertId = $command->insertId;

            $deferred->resolve($result);
        });

        return $deferred->promise();
    }

    public function queryStream($sql, $params = array())
    {
        $query = new Query($sql);
        if ($params) {
            $query->bindParamsFromArray($params);
        }

        $command = new QueryCommand($this);
        $command->setQuery($query);
        $this->_doCommand($command);

        $stream = new ThroughStream();

        // forward result set rows until result set end
        $command->on('result', function ($row) use ($stream) {
            $stream->write($row);
        });
        $command->on('end', function () use ($stream) {
            $stream->end();
        });

        // status reply (response without result set) ends stream without data
        $command->on('success', function () use ($stream) {
            $stream->end();
        });
        $command->on('error', function ($err) use ($stream) {
            $stream->emit('error', array($err));
            $stream->close();
        });

        return $stream;
    }

    public function ping()
    {
        return new Promise(function ($resolve, $reject) {
            $this->_doCommand(new PingCommand($this))
                ->on('error', function ($reason) use ($reject) {
                    $reject($reason);
                })
                ->on('success', function () use ($resolve) {
                    $resolve(true);
                });
        });
    }

    /**
     * {@inheritdoc}
     */
    public function setOption($name, $value)
    {
        $this->options[$name] = $value;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getOption($name, $default = null)
    {
        if (isset($this->options[$name])) {
            return $this->options[$name];
        }

        return $default;
    }

    /**
     * {@inheritdoc}
     */
    public function getState()
    {
        return $this->state;
    }

    public function quit()
    {
        return new Promise(function ($resolve, $reject) {
            $this->_doCommand(new QuitCommand($this))
                ->on('error', function ($reason) use ($reject) {
                    $reject($reason);
                })
                ->on('success', function () use ($resolve) {
                    $this->state = self::STATE_CLOSED;
                    $this->emit('end', [$this]);
                    $this->emit('close', [$this]);
                    $resolve(true);
                });
            $this->state = self::STATE_CLOSEING;
        });
    }

    /**
     * [internal] Connect to mysql server.
     *
     * This method will be invoked once after the `Connection` is initialized.
     *
     * @internal
     * @see \React\MySQL\Factory
     */
    public function doConnect($callback)
    {
        if ($this->state !== self::STATE_INIT) {
            throw new Exception('Connection not in idle state');
        }

        $this->state = self::STATE_CONNECTING;
        $options     = $this->options;
        $streamRef   = $this->stream;

        $errorHandler = function ($reason) use ($callback) {
            $this->state = self::STATE_AUTHENTICATE_FAILED;
            $callback($reason, $this);
        };
        $connectedHandler = function ($serverOptions) use ($callback) {
            $this->state = self::STATE_AUTHENTICATED;
            $this->serverOptions = $serverOptions;
            $callback(null, $this);
        };

        $this->connector
            ->connect($this->options['host'] . ':' . $this->options['port'])
            ->then(function ($stream) use (&$streamRef, $options, $errorHandler, $connectedHandler) {
                $streamRef = $stream;

                $stream->on('error', [$this, 'handleConnectionError']);
                $stream->on('close', [$this, 'handleConnectionClosed']);

                $parser = $this->parser = new Parser($stream, $this->executor);

                $parser->setOptions($options);

                $command = $this->_doCommand(new AuthenticateCommand($this));
                $command->on('authenticated', $connectedHandler);
                $command->on('error', $errorHandler);

                //$parser->on('close', $closeHandler);
                $parser->start();

            }, function (\Exception $error) use ($callback) {
                $this->state = self::STATE_CONNECT_FAILED;
                $error = new \RuntimeException('Unable to connect to database server', 0, $error);
                $this->handleConnectionError($error);
                $callback($error, $this);
            });
    }

    /**
     * {@inheritdoc}
     */
    public function getServerOptions()
    {
        return $this->serverOptions;
    }

    /**
     * @param Exception $err Error from socket.
     *
     * @return void
     * @internal
     */
    public function handleConnectionError($err)
    {
        $this->emit('error', [$err, $this]);
    }

    /**
     * @return void
     * @internal
     */
    public function handleConnectionClosed()
    {
        if ($this->state < self::STATE_CLOSEING) {
            $this->state = self::STATE_CLOSED;
            $this->emit('error', [new \RuntimeException('mysql server has gone away'), $this]);
        }

        // reject all pending commands if connection is closed
        while (!$this->executor->isIdle()) {
            $command = $this->executor->dequeue();
            $command->emit('error', array(
                new \RuntimeException('Connection lost'),
                $command,
                $this
            ));
        }
    }

    /**
     * @param CommandInterface $command The command which should be executed.
     * @return CommandInterface
     * @throws Exception Can't send command
     */
    protected function _doCommand(CommandInterface $command)
    {
        if ($command->equals(AbstractCommand::INIT_AUTHENTICATE)) {
            return $this->executor->undequeue($command);
        } elseif ($this->state >= self::STATE_CONNECTING && $this->state <= self::STATE_AUTHENTICATED) {
            return $this->executor->enqueue($command);
        } else {
            throw new Exception("Can't send command");
        }
    }
}
