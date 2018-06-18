<?php

namespace React\MySQL;

use React\EventLoop\LoopInterface;
use React\Socket\ConnectionInterface as SocketConnectionInterface;
use React\Socket\Connector;
use React\Socket\ConnectorInterface;
use React\MySQL\Commands\AuthenticateCommand;
use React\MySQL\Commands\PingCommand;
use React\MySQL\Commands\QueryCommand;
use React\MySQL\Commands\QuitCommand;

/**
 * Class Connection
 *
 * @package React\MySQL
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
     * @var Protocal\Parser
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
        $this->executor   = new Executor($this);
        $this->options    = $connectOptions + $this->options;
    }

    /**
     * {@inheritdoc}
     */
    public function query($sql, $callback = null, $params = null)
    {
        $query = new Query($sql);

        $command = new QueryCommand($this);
        $command->setQuery($query);

        $args = func_get_args();
        array_shift($args); // Remove $sql parameter.

        if (!is_callable($callback)) {
            if ($args) {
                $query->bindParamsFromArray($args);
            }

            return $this->_doCommand($command);
        }

        array_shift($args); // Remove $callback

        if ($args) {
            $query->bindParamsFromArray($args);
        }
        $this->_doCommand($command);

        $command->on('results', function ($rows, $command) use ($callback) {
            $callback($command, $this);
        });
        $command->on('error', function ($err, $command) use ($callback) {
            $callback($command, $this);
        });
        $command->on('success', function ($command) use ($callback) {
            $callback($command, $this);
        });

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function ping($callback)
    {
        if (!is_callable($callback)) {
            throw new \InvalidArgumentException('Callback is not a valid callable');
        }
        $this->_doCommand(new PingCommand($this))
            ->on('error', function ($reason) use ($callback) {
                $callback($reason, $this);
            })
            ->on('success', function () use ($callback) {
                $callback(null, $this);
            });
    }

    /**
     * {@inheritdoc}
     */
    public function selectDb($dbname)
    {
        return $this->query(sprintf('USE `%s`', $dbname));
    }

    /**
     * {@inheritdoc}
     */
    public function listFields()
    {
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

    /**
     * {@inheritdoc}
     */
    public function close($callback = null)
    {
        $this->_doCommand(new QuitCommand($this))
            ->on('success', function () use ($callback) {
                $this->state = self::STATE_CLOSED;
                $this->emit('end', [$this]);
                $this->emit('close', [$this]);
                if (is_callable($callback)) {
                    $callback($this);
                }
            });
        $this->state = self::STATE_CLOSEING;
    }

    /**
     * {@inheritdoc}
     */
    public function connect($callback)
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

                $parser = $this->parser = new Protocal\Parser($stream, $this->executor);

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
     * @param Command $command The command which should be executed.
     *
     * @return CommandInterface
     *
     * @throws Exception Cann't send command
     */
    protected function _doCommand(Command $command)
    {
        if ($command->equals(Command::INIT_AUTHENTICATE)) {
            return $this->executor->undequeue($command);
        } elseif ($this->state >= self::STATE_CONNECTING && $this->state <= self::STATE_AUTHENTICATED) {
            return $this->executor->enqueue($command);
        } else {
            throw new Exception("Can't send command");
        }
    }
}
