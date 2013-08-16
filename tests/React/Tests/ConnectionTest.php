<?php

namespace React\Tests;

use React\MySQL\Connection;

class ConnectionTest extends \PHPUnit_Framework_TestCase {

	private $connectOptions = array(
		'dbname' => 'test',
		'user'   => 'test',
		'passwd' => 'test'
	);
	
	public function testConnectWithInvalidPass() {
		$loop = \React\EventLoop\Factory::create();
		$conn = new Connection($loop, array('passwd' => 'invalidpass') + $this->connectOptions );
		$that = $this;
		
		$conn->connect(function ($err) use($that){
			$that->assertEquals("Access denied for user 'test'@'localhost' (using password: YES)", $err->getMessage());
		});
		$loop->run();
	}
	
	
	public function testConnectWithValidPass() {
		$loop = \React\EventLoop\Factory::create();
		$conn = new Connection($loop, $this->connectOptions );
		$that = $this;
		
		$conn->connect(function ($err) use($that, $loop){
			$that->assertEquals(null, $err);
			$loop->stop();
		});
		$loop->run();
	}
}
