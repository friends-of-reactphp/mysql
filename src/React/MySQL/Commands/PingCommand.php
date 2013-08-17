<?php

namespace React\MySQL\Commands;

use React\MySQL\Command;
use React\MySQL\Protocal\Constants;

class PingCommand extends Command {
	
	public function getId() {
		return self::PING;
	}
	
	public function buildPacket() {
		
	}
	
	public function getSql() {
		return '';
	}
}
