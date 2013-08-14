<?php
namespace React\MySQL;

use React\EventLoop\LoopInterface;
use React\SocketClient\ConnectorInterface;

class Client {
	
	private $loop;
	private $connector;
	private $secureConnector;
	private $params;
	
	public function __construct(LoopInterface $loop, ConnectorInterface $connector, ConnectorInterface $secureConnector, $params) {
		$this->loop = $loop;
		$this->connector = $connector;
		$this->secureConnector = $secureConnector;
		$this->params = $params;
	}
	
	
	
	public function auth($username, $password) {
		
		$request = new Request($this->loop, $this->connector, $this->params);
		$request->write('fff');
		return $request;
	}
	
	public function query($sql) {
		
	}
}
