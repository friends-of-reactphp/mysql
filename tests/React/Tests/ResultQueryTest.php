<?php

namespace React\MySQL;

class ResultQueryTest extends \PHPUnit_Framework_TestCase {
	
	public function testSimpleSelect() {
		$loop = \React\EventLoop\Factory::create();
		
		$connection = new \React\MySQL\Connection($loop, array(
			'dbname' => 'test',
			'user'   => 'test',
			'passwd' => 'test',
		));
		
		$connection->connect(function (){});
		$that  = $this;
		$connection->query('select * from book', function ($err, $rows, $conn) use ($that, $loop){
			$that->assertEquals(null, $err);
			$that->assertEquals(2, count($rows));
			$loop->stop();
		});
		$loop->run();
		
		$connection->connect(function (){});
		
		$connection->query('select * from invalid_table', function ($err, $rows, $conn) use ($that, $loop){
			$that->assertEquals(null, $rows);
			$that->assertEquals("Table 'test.invalid_table' doesn't exist", $err->getMessage());
			$loop->stop();
		});
		$loop->run();
	}
}
