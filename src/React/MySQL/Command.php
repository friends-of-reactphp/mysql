<?php

namespace React\MySQL;

use Evenement\EventEmitter;

class Command extends EventEmitter {
	
	private $executor;
	
	private $cmd;
	
	private $q;
	
	/**
	 * Construtor.
	 * 
	 * @param integer $cmd
	 * @param string $q
	 */
	public function __construct($executor, $cmd, $q = '') {
		$this->executor = $executor;
		$this->cmd      = $cmd;
		$this->q        = $q;
	}
	
	public function execute() {
		$this->executor->append($this);
		return $this;
	}
}
