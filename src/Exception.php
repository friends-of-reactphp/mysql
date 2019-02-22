<?php

namespace React\MySQL;

class Exception extends \Exception
{
  public $command;

  public function setCommand($command){
    $this->command = $command;
    return $this;
	}
}
