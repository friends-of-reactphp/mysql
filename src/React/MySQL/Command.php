<?php

namespace React\MySQL;

use Evenement\EventEmitter;

class Command extends EventEmitter {
	
	private $executor;
	
	public  $cmd;
	
	public  $query;
	
	/**
	 * Construtor.
	 * 
	 * @param integer $cmd
	 * @param string $q
	 */
	public function __construct($executor, $cmd, $query = '') {
		$this->executor = $executor;
		$this->cmd      = $cmd;
		$this->query    = $query;
	}
	
	public function getSql() {
		if ($this->query instanceof Query) {
			return $this->query->getSql();
		}
		return $this->query;
	}
	
	public function getConn() {
		return $this->executor->getConn();
	}
}
