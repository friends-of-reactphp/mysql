<?php

namespace React\MySQL\Commands;

use React\MySQL\Command;
use React\MySQL\Protocal\Constants;

class QueryCommand extends Command {
	
	public function getId() {
		return self::QUERY;
	}
	
	public function getSql() {
		$query = $this->getState('query');
		if ($query instanceof Query) {
			return $query->getSql();
		}
		return $query;
	}
	
	public function buildPacket() {
		
	}
}
