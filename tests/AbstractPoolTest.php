<?php

namespace React\Tests\MySQL;

use React\EventLoop\Factory;
use React\EventLoop\LoopInterface;
use React\MySQL\Connection;
use React\MySQL\Pool\PoolInterface;
use React\MySQL\Pool\PoolQueryResult;

class AbstractPoolTest extends BaseTestCase
{

    /**
     * @var PoolInterface|\PHPUnit_Framework_MockObject_MockObject
     */
    private $pool;

    /**
     * @var \ReflectionClass
     */
    private $reflection;

    /**
     * {@inheritDoc}
     */
    protected function setUp()
    {
        $this->pool = $this->getMockForAbstractClass('React\MySQL\Pool\AbstractPool');
        $this->reflection = new \ReflectionClass($this->pool);
    }

    /**
     * @return void
     */
    public function testQuery()
    {
        /** @var LoopInterface $loop */
        $loop = Factory::create();

        $this->pool
            ->expects($this->once())
            ->method('getConnection')
            ->willReturnCallback(function () use ($loop) {
                $connection = new Connection($loop, $this->getConnectionOptions());
                $connection->connect(function () {});

                return \React\Promise\resolve($connection);
            });

        $promise = $this->pool->query('select * from book');
        $this->assertInstanceOf('React\Promise\PromiseInterface', $promise);

        $promise
            ->then(function ($result) use ($loop) {
                /** @var PoolQueryResult $result */
                $command = $result->getCmd();

                $this->assertInstanceOf('React\MySQL\Pool\PoolQueryResult', $result);
                $this->assertInstanceOf('React\MySQL\Commands\QueryCommand', $command);
                $this->assertSame($this->pool, $result->getPool());
                $this->assertEquals(false, $command->hasError());
                $this->assertEquals(2, count($command->resultRows));
                $loop->stop();
            })
            ->done();

        $loop->run();
    }
}
