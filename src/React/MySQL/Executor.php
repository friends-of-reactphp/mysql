<?php

namespace React\MySQL;

use Evenement\EventEmitter;

class Executor extends EventEmitter {
	
	private $client;
	
	private $queue;
	
	public function __construct($client) {
		$this->client = $client;
		$this->queue = new \SplQueue();
	}
	
	public function isIdle() {
		return $this->queue->isEmpty();
	}
	
	public function enqueue($command) {
		return $this->queue->enqueue($command);
	}
	
	public function dequeue() {
		return $this->queue->dequeue();
	}
}
