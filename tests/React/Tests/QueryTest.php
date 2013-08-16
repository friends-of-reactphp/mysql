<?php

namespace React\Tests;

use React\MySQL\Query;

class QueryTest extends \PHPUnit_Framework_TestCase {
	
	public function testBindParams() {
		$query = new Query('select * from test where id = ? and name = ?');
		$sql = $query->params(100, 'test')->getSql();
		$this->assertEquals("select * from test where id = 100 and name = 'test'", $sql);
	}
	
}
