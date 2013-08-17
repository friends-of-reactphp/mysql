<?php

namespace React\MySQL;

use Evenement\EventEmitter;
use React\Stream\WritableStreamInterface;
use React\EventLoop\LoopInterface;
use React\SocketClient\ConnectorInterface;
use React\Stream\Stream;
use React\Promise\Deferred;
use React\MySQL\Protocal\Constants;
use React\SocketClient\Connector;


class Connection extends EventEmitter implements WritableStreamInterface {

	const STATE_INIT                = 0;
	const STATE_CONNECT_FAILED      = 1;
	const STATE_AUTHENTICATE_FAILED = 2;
	const STATE_CONNECTING          = 3;
	const STATE_CONNECTED           = 4;
	const STATE_AUTHENTICATED       = 5;
	const STATE_DISCONNECTING       = 6;
	const STATE_DISCONNECTED        = 7;
	const STATE_END                 = 8;
	
	private $loop;
	
	private $connector;
	
	private $options = array(
		'host'   => '127.0.0.1',
		'port'   => 3306,
		'user'   => 'root',
		'passwd' => '',
		'dbname' => '',		
	);
	
	private $serverOptions;
	
	private $executor;
	
	private $state = self::STATE_INIT;
	
	private $stream;
	
	private $buffer;
	/**
	 * @var Protocal\Parser
	 */
	public $parser;
	
	public function __construct(LoopInterface $loop, array $connectOptions = array()) {
		$this->loop       = $loop;
		$resolver         = (new \React\Dns\Resolver\Factory())->createCached('8.8.8.8', $loop);
		$this->connector  = new Connector($loop, $resolver);;
		$this->executor   = new Executor($this);
		$this->options    = $connectOptions + $this->options;
	}
	
	/**
	 * Do a async query.
	 * 
	 * @param string $sql
	 * @return \React\Promise\DeferredPromise
	 */
	public function query($sql) {
		$numArgs = func_num_args();
		if ($numArgs === 0) {
			throw new \InvalidArgumentException('Required at least 1 argument');
		}
		
		$command = new Command($this->executor, Constants::COM_QUERY, $sql);
		$query = $this->_doCommand($command);
		if ($numArgs === 1) {
			return $command;
		}
		
		$func = func_get_arg(1);
		$that = $this;
		
		$command->on('results', function ($rows) use($func, $that){
			$func(null, $rows, $that);
		});
		$command->on('error', function ($err) use ($func, $that){
			$func($err, null, $that);
		});
	}
	
	public function execute($sql) {
		return $this->doCommand(Constants::COM_QUERY, $sql);
	}
	
	public function ping() {
		return $this->doCommand(Constants::COM_PING, '');
	}
	
	public function selectDb($dbname) {
		return $this->query(sprinf('USE `%s`', $dbname));
	}
	
	public function setParam($name, $value) {
		$this->params[$name] = $value;
		return $this;
	}
	
	public function getParam($name, $default = null) {
		if (isset($this->params[$name])) {
			return $this->params[$name];
		}
		return $default;
	}
	
	public function isWritable() {
		return self::STATE_END > $this->state;
	}
	
	public function write($data) {
		if (!$this->isWritable()) {
			
			return;
		}
		if (self::STATE_WRITING <= $this->state) {
			throw new \LogicException('Data already written.');
		}
		$this->state = self::STATE_WRITING;
	}
	
	
	public function end($data = null) {
		
	}
	
	public function close() {
		
	}
	
	/**
	 * Connnect to mysql server.
	 * 
	 * @param callable $callback
	 * 
	 * @throws \Exception
	 */
	public function connect() {
		$this->state = self::STATE_CONNECTING;
		$options     = $this->options;
		$that        = $this;
		$streamRef   = $this->stream;
		$args        = func_get_args();
		
		if (count($args) > 0) {
			$closeHandler = function () use ($args, $that){
				$args[0]();
			};
			$errorHandler = function ($reason) use ($args, $that){
				$that->state = $that::STATE_AUTHENTICATE_FAILED;
				$args[0]($reason, $that);
			};
			$connectedHandler = function ($serverOptions) use ($args, $that) {
				$that->state = $that::STATE_AUTHENTICATED;
				$that->serverOptions = $serverOptions;
				$args[0](null, $that);
			};
			
			$this->connector
				->create($this->options['host'], $this->options['port'])
				->then(function ($stream) use (&$streamRef, $that, $options, $closeHandler, $errorHandler, $connectedHandler){
					$streamRef = $stream;
					
					$parser = $that->parser = new Protocal\Parser($stream, $that->executor);
					
					$parser->setOptions($options);
					
					$command = $that->_doCommand($that->createCommand(Constants::COM_INIT_AUTHENTICATE));
					$command->on('authenticated', $connectedHandler);
					$command->on('error', $errorHandler);
					
					//$parser->on('close', $closeHandler);
					$parser->start();
					
					
				}, $closeHandler);
		}else {
			throw new \Exception('Not Implemented');
		}
	}
	
	
	protected function _doCommand(Command $command) {
		if ($command->cmd === Constants::COM_INIT_AUTHENTICATE){
			return $this->executor->undequeue($command);
		}elseif ($this->state >= self::STATE_CONNECTING && $this->state <= self::STATE_AUTHENTICATED) {
			return $this->executor->enqueue($command);
		}else {
			throw Exception("Cann't send command");
		}
	}
	
	public function createCommand($cmd, $query = '') {
		return new Command($this->executor, $cmd, $query);
	}
	
	public function getServerOptions() {
		return $this->serverOptions;
	}
}
