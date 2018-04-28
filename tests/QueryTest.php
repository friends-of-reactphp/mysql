<?php

namespace React\Tests\MySQL;

use React\MySQL\Query;

class QueryTest extends \PHPUnit_Framework_TestCase
{
    public function testBindParams()
    {
        $query = new Query('select * from test where id = ? and name = ?');
        $sql = $query->bindParams(100, 'test')->getSql();
        $this->assertEquals("select * from test where id = 100 and name = 'test'", $sql);

        $query = new Query('select * from test where id in (?) and name = ?');
        $sql = $query->bindParams([1, 2], 'test')->getSql();
        $this->assertEquals("select * from test where id in (1,2) and name = 'test'", $sql);

        $query = new Query('select * from test where id in (?) and name = ?');
        $sql = $query->bindParams([1, 2], 'Iñtërnâtiônàlizætiøn')->getSql();
        $this->assertEquals("select * from test where id in (1,2) and name = 'Iñtërnâtiônàlizætiøn'", $sql);

        $query = new Query('select * from test where id = :id and name = :name');
        $sql = $query->bindParams([':id' => 100, ':name' => 'test'])->getSql();
        $this->assertEquals("select * from test where id = 100 and name = 'test'", $sql);

        $query = new Query('select * from test where id = :id and name = ?');
        $sql = $query->bindParams('test', [':id' => 100])->getSql();
        $this->assertEquals("select * from test where id = 100 and name = 'test'", $sql);

        $query = new Query('select * from test where name = ? or id = :id');
        $sql = $query->bindParams('test', [':id' => 100])->getSql();
        $this->assertEquals("select * from test where name = 'test' or id = 100", $sql);

        $query = new Query('select * from test where name = ? or id = :id');
        $sql = $query->bindParams('test', ['id' => 100])->getSql();
        $this->assertEquals("select * from test where name = 'test' or id = 100", $sql);

        $query = new Query('select * from test where name = :name or id IN (?)');
        $sql = $query->bindParams([':name' => 'Iñtërnâtiônàlizætiøn'], [1, 2])->getSql();
        $this->assertEquals("select * from test where name = 'Iñtërnâtiônàlizætiøn' or id IN (1,2)", $sql);
    }

    public function testBindParamsFromArray()
    {
        $query = new Query('select * from test where name = :name or id = ?');
        $sql = $query->bindParamsFromArray([':name' => 'Iñtërnâtiônàlizætiøn', 2])->getSql();
        $this->assertEquals("select * from test where name = 'Iñtërnâtiônàlizætiøn' or id = 2", $sql);

        $query = new Query('select * from test where name = ? or id = :id');
        $sql = $query->bindParamsFromArray(['Iñtërnâtiônàlizætiøn', ':id' => 2])->getSql();
        $this->assertEquals("select * from test where name = 'Iñtërnâtiônàlizætiøn' or id = 2", $sql);

        $query = new Query('select * from test where name = ? or id = :id');
        $sql = $query->bindParamsFromArray([':id' => 2])->getSql();
        $this->assertEquals("select * from test where name = ? or id = 2", $sql);

        $query = new Query('select * from test where name = :name or id = :id');
        $sql = $query->bindParamsFromArray([':name' => 'Iñtërnâtiônàlizætiøn', ':id' => 2])->getSql();
        $this->assertEquals("select * from test where name = 'Iñtërnâtiônàlizætiøn' or id = 2", $sql);

        $query = new Query('select * from test where name = :name');
        $sql = $query->bindParamsFromArray([':name' => 'Iñtërnâtiônàlizætiøn', ':id' => 2])->getSql();
        $this->assertEquals("select * from test where name = 'Iñtërnâtiônàlizætiøn'", $sql);

        $query = new Query('select * from test where id = :id');
        $sql = $query->bindParamsFromArray([':name' => 'Iñtërnâtiônàlizætiøn', ':id' => 2])->getSql();
        $this->assertEquals("select * from test where id = 2", $sql);

        $query = new Query('select * from test where name = :name');
        $sql = $query->bindParamsFromArray([':name_non' => 'Iñtërnâtiônàlizætiøn', ':id' => 2])->getSql();
        $this->assertEquals("select * from test where name = :name", $sql);

        $query = new Query('select * from test where id = :id');
        $sql = $query->bindParamsFromArray([':name' => 'Iñtërnâtiônàlizætiøn', ':id_non' => 2])->getSql();
        $this->assertEquals("select * from test where id = :id", $sql);
    }

    public function testGetSqlReturnsQuestionMarkReplacedWhenBound()
    {
        $query = new Query('select ?');
        $sql = $query->bindParams('hello')->getSql();
        $this->assertEquals("select 'hello'", $sql);
    }

    public function testGetSqlReturnsQuestionMarkReplacedWhenBoundFromLastCall()
    {
        $query = new Query('select ?');
        $sql = $query->bindParams('foo')->bindParams('bar')->getSql();
        $this->assertEquals("select 'bar'", $sql);
    }

    public function testGetSqlReturnsQuestionMarkReplacedWithNullValueWhenBound()
    {
        $query = new Query('select ?');
        $sql = $query->bindParams(null)->getSql();
        $this->assertEquals("select NULL", $sql);
    }

    public function testGetSqlReturnsQuestionMarkReplacedFromBoundWhenBound()
    {
        $query = new Query('select CONCAT(?, ?)');
        $sql = $query->bindParams('hello??', 'world??')->getSql();
        $this->assertEquals("select CONCAT('hello??', 'world??')", $sql);
    }

    public function testGetSqlReturnsQuestionMarksAsIsWhenNotBound()
    {
        $query = new Query('select "hello?"');
        $sql = $query->getSql();
        $this->assertEquals("select \"hello?\"", $sql);
    }

    public function testEscapeChars()
    {
        $query = new Query('');
        $this->assertEquals('\\\\', $query->escape('\\'));
        $this->assertEquals('\"', $query->escape('"'));
        $this->assertEquals("\'", $query->escape("'"));
        $this->assertEquals("\\n", $query->escape("\n"));
        $this->assertEquals("\\r", $query->escape("\r"));
        $this->assertEquals("foo\\0bar", $query->escape("foo" . chr(0) . "bar"));
        $this->assertEquals("n%3A", $query->escape("n%3A"));
        //$this->assertEquals('§ä¨ì¥H¤U¤º®e\\\\§ä¨ì¥H¤U¤º®e', $query->escape('§ä¨ì¥H¤U¤º®e\\§ä¨ì¥H¤U¤º®e'));
    }
}
