<?php

namespace React\MySQL;

use Evenement\EventEmitter;
use React\Stream\WritableStreamInterface;
use React\EventLoop\LoopInterface;
use React\SocketClient\ConnectorInterface;
use React\Stream\Stream;


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
	
	public function __construct(LoopInterface $loop, ConnectorInterface $connector, $params) {
		$this->loop = $loop;
		$this->connector = $connector;
		$this->params = $params;
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

		$that = $this;
		$streamRef = &$this->stream;
		$stateRef = &$this->state;

		$r = $this->connect()
			->then(function (Stream $stream) use ($that, &$streamRef, &$stateRef) {
				$streamRef = $stream;

				$stream->on('drain', array($that, 'handleDrain'));
				$stream->on('data', array($that, 'handleData'));
				$stream->on('end', array($that, 'handleEnd'));
				$stream->on('error', array($that, 'handleError'));
				
				
			}, 
			array($this, 'handleError'));
	}
	
	public function handleDrain() {
	}
	
	public function handleData($data) {
		$this->buffer .= $data;
		var_dump($this->buffer);
	}
	
	public function handleError($error) {
		if ($this->state >= self::STATE_END) {
			return;
		}
		$this->state = self::STATE_END;
		$this->emit('error', array($error, $this));
		$this->emit('end', array($error, $this));
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
