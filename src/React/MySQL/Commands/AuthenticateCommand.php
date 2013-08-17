<?php

namespace React\MySQL\Commands;

use React\MySQL\Command;
use React\MySQL\Protocal\Constants;

class AuthenticateCommand extends Command {
	
	public function getId() {
		return self::INIT_AUTHENTICATE;
	}
	
	public function buildPacket() {

	}
}
