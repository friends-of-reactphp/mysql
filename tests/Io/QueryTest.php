<?php

namespace React\Tests\Mysql\Io;

use PHPUnit\Framework\TestCase;
use React\Mysql\Io\Query;

class QueryTest extends TestCase
{
    public function testBindParams()
    {
        $query = new Query('select * from test where id = ? and name = ?', [100, 'test']);
        $this->assertEquals("select * from test where id = 100 and name = 'test'", $query->getSql());

        $query = new Query('select * from test where id in (?,?) and name = ?', [1, 2, 'test']);
        $this->assertEquals("select * from test where id in (1,2) and name = 'test'", $query->getSql());
        /*
        $query = new Query('select * from test where id = :id and name = :name', [':id' => 100, ':name' => 'test']);
        $this->assertEquals("select * from test where id = 100 and name = 'test'", $query->getSql());

        $query = new Query('select * from test where id = :id and name = ?', ['test', ':id' => 100]);
        $this->assertEquals("select * from test where id = 100 and name = 'test'", $query->getSql());
        */
    }

    public function testGetSqlReturnsQuestionMarkReplacedWhenBound()
    {
        $query = new Query('select ?', ['hello']);
        $this->assertEquals("select 'hello'", $query->getSql());
    }

    public function testGetSqlReturnsQuestionMarkReplacedWithNullValueWhenBound()
    {
        $query = new Query('select ?', [null]);
        $this->assertEquals("select NULL", $query->getSql());
    }

    public function testGetSqlReturnsQuestionMarkReplacedFromBoundWhenBound()
    {
        $query = new Query('select CONCAT(?, ?)', ['hello??', 'world??']);
        $this->assertEquals("select CONCAT('hello??', 'world??')", $query->getSql());
    }

    public function testGetSqlReturnsQuestionMarksAsIsWhenNotBound()
    {
        $query = new Query('select "hello?"');
        $this->assertEquals("select \"hello?\"", $query->getSql());
    }

    public function testEscapeChars()
    {
        $query = new Query('');
        $this->assertEquals('\\\\', $query->escape('\\'));
        $this->assertEquals("''", $query->escape("'"));
        $this->assertEquals("foo\0bar", $query->escape("foo" . chr(0) . "bar"));
        $this->assertEquals("n%3A", $query->escape("n%3A"));
        $this->assertEquals('§ä¨ì¥H¤U¤º®e\\\\§ä¨ì¥H¤U¤º®e', $query->escape('§ä¨ì¥H¤U¤º®e\\§ä¨ì¥H¤U¤º®e'));
    }
}
