<?php

namespace React\Mysql\Io;

use Evenement\EventEmitter;
use React\EventLoop\LoopInterface;
use React\Mysql\Commands\CommandInterface;
use React\Mysql\Commands\PingCommand;
use React\Mysql\Commands\QueryCommand;
use React\Mysql\Commands\QuitCommand;
use React\Mysql\Exception;
use React\Mysql\MysqlResult;
use React\Promise\Deferred;
use React\Promise\Promise;
use React\Socket\ConnectionInterface as SocketConnectionInterface;

/**
 * @internal
 * @see \React\Mysql\MysqlClient
 */
class Connection extends EventEmitter
{
    const STATE_AUTHENTICATED       = 5;
    const STATE_CLOSING             = 6;
    const STATE_CLOSED              = 7;

    /**
     * @var Executor
     */
    private $executor;

    /**
     * @var int one of the state constants (may change, but should be used readonly from outside)
     * @see self::STATE_*
     */
    public $state = self::STATE_AUTHENTICATED;

    /**
     * @var SocketConnectionInterface
     */
    private $stream;

    /** @var Parser */
    private $parser;

    /** @var LoopInterface */
    private $loop;

    /** @var float */
    private $idlePeriod = 0.001;

    /** @var ?\React\EventLoop\TimerInterface */
    private $idleTimer;

    /** @var int */
    private $pending = 0;

    /**
     * Connection constructor.
     *
     * @param SocketConnectionInterface $stream
     * @param Executor                  $executor
     * @param Parser                    $parser
     * @param LoopInterface             $loop
     * @param ?float                    $idlePeriod
     */
    public function __construct(SocketConnectionInterface $stream, Executor $executor, Parser $parser, LoopInterface $loop, $idlePeriod)
    {
        $this->stream   = $stream;
        $this->executor = $executor;
        $this->parser   = $parser;

        $this->loop = $loop;
        if ($idlePeriod !== null) {
            $this->idlePeriod = $idlePeriod;
        }

        $stream->on('error', [$this, 'handleConnectionError']);
        $stream->on('close', [$this, 'handleConnectionClosed']);
    }

    /**
     * busy executing some command such as query or ping
     *
     * @return bool
     * @throws void
     */
    public function isBusy()
    {
        return $this->parser->isBusy() || !$this->executor->isIdle();
    }

    /**
     * {@inheritdoc}
     */
    public function query($sql, array $params = [])
    {
        $query = new Query($sql);
        if ($params) {
            $query->bindParamsFromArray($params);
        }

        $command = new QueryCommand();
        $command->setQuery($query);
        try {
            $this->_doCommand($command);
        } catch (\Exception $e) {
            return \React\Promise\reject($e);
        }

        $this->awake();
        $deferred = new Deferred();

        // store all result set rows until result set end
        $rows = [];
        $command->on('result', function ($row) use (&$rows) {
            $rows[] = $row;
        });
        $command->on('end', function () use ($command, $deferred, &$rows) {
            $result = new MysqlResult();
            $result->resultFields = $command->fields;
            $result->resultRows = $rows;
            $result->warningCount = $command->warningCount;

            $rows = [];

            $this->idle();
            $deferred->resolve($result);
        });

        // resolve / reject status reply (response without result set)
        $command->on('error', function ($error) use ($deferred) {
            $this->idle();
            $deferred->reject($error);
        });
        $command->on('success', function () use ($command, $deferred) {
            $result = new MysqlResult();
            $result->affectedRows = $command->affectedRows;
            $result->insertId = $command->insertId;
            $result->warningCount = $command->warningCount;

            $this->idle();
            $deferred->resolve($result);
        });

        return $deferred->promise();
    }

    public function queryStream($sql, $params = [])
    {
        $query = new Query($sql);
        if ($params) {
            $query->bindParamsFromArray($params);
        }

        $command = new QueryCommand();
        $command->setQuery($query);
        $this->_doCommand($command);
        $this->awake();

        $stream = new QueryStream($command, $this->stream);
        $stream->on('close', function () {
            $this->idle();
        });

        return $stream;
    }

    public function ping()
    {
        return new Promise(function ($resolve, $reject) {
            $command = $this->_doCommand(new PingCommand());
            $this->awake();

            $command->on('success', function () use ($resolve) {
                $this->idle();
                $resolve(null);
            });
            $command->on('error', function ($reason) use ($reject) {
                $this->idle();
                $reject($reason);
            });
        });
    }

    public function quit()
    {
        return new Promise(function ($resolve, $reject) {
            $command = $this->_doCommand(new QuitCommand());
            $this->state = self::STATE_CLOSING;

            // mark connection as "awake" until it is closed, so never "idle"
            $this->awake();

            $command->on('success', function () use ($resolve) {
                $resolve(null);
                $this->close();
            });
            $command->on('error', function ($reason) use ($reject) {
                $reject($reason);
                $this->close();
            });
        });
    }

    public function close()
    {
        if ($this->state === self::STATE_CLOSED) {
            return;
        }

        $this->state = self::STATE_CLOSED;
        $remoteClosed = $this->stream->isReadable() === false && $this->stream->isWritable() === false;
        $this->stream->close();

        if ($this->idleTimer !== null) {
            $this->loop->cancelTimer($this->idleTimer);
            $this->idleTimer = null;
        }

        // reject all pending commands if connection is closed
        while (!$this->executor->isIdle()) {
            $command = $this->executor->dequeue();
            assert($command instanceof CommandInterface);

            if ($remoteClosed) {
                $command->emit('error', [new \RuntimeException(
                    'Connection closed by peer (ECONNRESET)',
                    \defined('SOCKET_ECONNRESET') ? \SOCKET_ECONNRESET : 104
                )]);
            } else {
                $command->emit('error', [new \RuntimeException(
                    'Connection closing (ECONNABORTED)',
                    \defined('SOCKET_ECONNABORTED') ? \SOCKET_ECONNABORTED : 103
                )]);
            }
        }

        $this->emit('close');
        $this->removeAllListeners();
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
        if ($this->state < self::STATE_CLOSING) {
            $this->emit('error', [new \RuntimeException(
                'Connection closed by peer (ECONNRESET)',
                \defined('SOCKET_ECONNRESET') ? \SOCKET_ECONNRESET : 104
            )]);
        }

        $this->close();
    }

    /**
     * @param CommandInterface $command The command which should be executed.
     * @return CommandInterface
     * @throws Exception Can't send command
     */
    protected function _doCommand(CommandInterface $command)
    {
        if ($this->state !== self::STATE_AUTHENTICATED) {
            throw new \RuntimeException(
                'Connection ' . ($this->state === self::STATE_CLOSED ? 'closed' : 'closing'). ' (ENOTCONN)',
                \defined('SOCKET_ENOTCONN') ? \SOCKET_ENOTCONN : 107
            );
        }

        return $this->executor->enqueue($command);
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

        if ($this->pending < 1 && $this->idlePeriod >= 0 && $this->state === self::STATE_AUTHENTICATED) {
            $this->idleTimer = $this->loop->addTimer($this->idlePeriod, function () {
                // soft-close connection and emit close event afterwards both on success or on error
                $this->idleTimer = null;
                $this->quit()->then(null, function () {
                    // ignore to avoid reporting unhandled rejection
                });
            });
        }
    }
}
