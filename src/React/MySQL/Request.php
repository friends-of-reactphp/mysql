<?php

namespace React\MySQL;

use Evenement\EventEmitter;
use React\Stream\WritableStreamInterface;
use React\EventLoop\LoopInterface;
use React\SocketClient\ConnectorInterface;
use React\Stream\Stream;
use React\Promise\Deferred;


class Request extends EventEmitter implements WritableStreamInterface {

	const STATE_INIT    = 0;
	const STATE_WRITING = 1;
	const STATE_WRITEN  = 2;
	const STATE_END     = 3;
	
	private $loop;
	private $connector;
	private $params;
	
	private $state = self::STATE_INIT;
	
	private $stream;
	private $buffer;
	public $parser;
	
	public function __construct(LoopInterface $loop, ConnectorInterface $connector) {
		$this->loop = $loop;
		$this->connector = $connector;
	}
	
	/**
	 * Authentication.
	 * 
	 * @param array $options
	 * @return \React\Promise\DeferredPromise
	 */
	public function auth(array $options) {
		$deferred         = new Deferred();
		$that             = $this;
		$streamRef        = &$this->stream;
		
		$errorHandler     = function ($reason) use ($deferred) {
			$deferred->reject($reason);
		};
		
		$connectedHandler = function ($options) use ($deferred) {
			$deferred->resolve($options);
		};
		
		$this->connect()
			->then(function ($stream) use (&$streamRef, $that, $options, $errorHandler, $connectedHandler){
				$streamRef = $stream;
				
				$parser = $that->parser = new Protocal\Parser($stream);
				
				$parser->setOptions($options);
				$parser->on('error', $errorHandler);
				$parser->on('connected', $connectedHandler);
				
			}, $errorHandler);
		
		
		return $deferred->promise();
	}
	
	/**
	 * Do a async query.
	 * 
	 * @param string $sql
	 * @return \React\Promise\DeferredPromise
	 */
	public function query($sql) {
		$deferred = new Deferred();
		$that     = $this;
		
		$this->parser->query($sql, function ($data) use ($deferred){
			if ($data instanceof Exception) {
				$deferred->reject($data);
			}else {
				$deferred->resolve($data);
			}
		});
		return $deferred->promise();
	}
	
	public function execute($sql) {
		
	}
	
	public function selectDb($dbname) {
		
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
	
	public function handleDrain() {
	}
	
	public function handleData($data) {
		$this->buffer .= $data;
		var_dump($this->buffer);
	}
	
	public function handleConnected($connectOptions) {
		var_dump($connectOptions);
	}
	
	public function end($data = null) {
		
	}
	
	public function close() {
		
	}
	
	protected function connect() {
		return $this->connector
			->create($this->getParam('host', '127.0.0.1'), $this->getParam('port', 3306));
	}
}
