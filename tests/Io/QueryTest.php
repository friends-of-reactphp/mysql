<?php

namespace React\Tests\MySQL\Io;

use PHPUnit\Framework\TestCase;
use React\MySQL\Io\Query;

class QueryTest extends TestCase
{
    public function testBindParams()
    {
        $query = new Query('select * from test where id = ? and name = ?');
        $sql   = $query->bindParams(100, 'test')->getSql();
        $this->assertEquals("select * from test where id = 100 and name = 'test'", $sql);

        $query = new Query('select * from test where id in (?) and name = ?');
        $sql   = $query->bindParams([1, 2], 'test')->getSql();
        $this->assertEquals("select * from test where id in (1,2) and name = 'test'", $sql);
        /*
        $query = new Query('select * from test where id = :id and name = :name');
        $sql   = $query->params([':id' => 100, ':name' => 'test'])->getSql();
        $this->assertEquals("select * from test where id = 100 and name = 'test'", $sql);

        $query = new Query('select * from test where id = :id and name = ?');
        $sql   = $query->params('test', [':id' => 100])->getSql();
        $this->assertEquals("select * from test where id = 100 and name = 'test'", $sql);
        */
    }

    public function testNamedParams()
    {
        $query = new Query('select * from test where id = :id and name = :name');
        $sql   = $query->bindParamsFromArray([
            'id' => 100,
            'name' => 'test'
        ])->getSql();
        $this->assertEquals("select * from test where id = 100 and name = 'test'", $sql);
    }

    public function testNamedParamsWithPrefix()
    {
        $query = new Query('select * from test where id = :id and name = :name');
        $sql   = $query->bindParamsFromArray([
            ':id' => 100,
            ':name' => 'test'
        ])->getSql();
        $this->assertEquals("select * from test where id = 100 and name = 'test'", $sql);
    }

    public function testNamedParamsWithInClause()
    {
        $query = new Query('select * from test where id in (:in) and name = :name');
        $sql   = $query->bindParamsFromArray([
            'in' => [1, 2],
            'name' => 'test'
        ])->getSql();
        $this->assertEquals("select * from test where id in (1,2) and name = 'test'", $sql);
    }

    public function testMixedNamedParams()
    {
        $query = new Query('select * from test where id in (?) and name = :name');
        $sql   = $query->bindParamsFromArray([
            [1, 2],
            'name' => 'test'
        ])->getSql();
        $this->assertEquals("select * from test where id in (1,2) and name = 'test'", $sql);
    }

    public function testMixedNamedParamsWithInClause()
    {
        $query = new Query('select * from test where id in (:in) and name = ?');
        $sql   = $query->bindParamsFromArray([
            'in' => [1, 2],
            'test'
        ])->getSql();
        $this->assertEquals("select * from test where id in (1,2) and name = 'test'", $sql);
    }

    public function testGetSqlReturnsQuestionMarkReplacedWhenBound()
    {
        $query = new Query('select ?');
        $sql   = $query->bindParams('hello')->getSql();
        $this->assertEquals("select 'hello'", $sql);
    }

    public function testGetSqlReturnsQuestionMarkReplacedWhenBoundFromLastCall()
    {
        $query = new Query('select ?');
        $sql   = $query->bindParams('foo')->bindParams('bar')->getSql();
        $this->assertEquals("select 'bar'", $sql);
    }

    public function testGetSqlReturnsQuestionMarkReplacedWithNullValueWhenBound()
    {
        $query = new Query('select ?');
        $sql   = $query->bindParams(null)->getSql();
        $this->assertEquals("select NULL", $sql);
    }

    public function testGetSqlReturnsQuestionMarkReplacedFromBoundWhenBound()
    {
        $query = new Query('select CONCAT(?, ?)');
        $sql   = $query->bindParams('hello??', 'world??')->getSql();
        $this->assertEquals("select CONCAT('hello??', 'world??')", $sql);
    }

    public function testGetSqlReturnsNamedParamsReplacedFromBoundWhenBound()
    {
        $query = new Query('select CONCAT(:param1, :param2)');
        $sql   = $query->bindParamsFromArray([
            'param1' => ':param2',
            'param2' => 'world'
        ])->getSql();
        $this->assertEquals("select CONCAT(':param2', 'world')", $sql);
    }

    public function testGetSqlReturnsQuestionMarksAsIsWhenNotBound()
    {
        $query = new Query('select "hello?"');
        $sql   = $query->getSql();
        $this->assertEquals("select \"hello?\"", $sql);
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
