<?php

namespace React\Tests\MySQL;

use React\EventLoop\Factory;
use React\EventLoop\LoopInterface;
use React\MySQL\Pool\PoolFactory;

/**
 * Class PoolFactoryTest
 *
 * @package React\Tests\MySQL
 */
class PoolFactoryTest extends BaseTestCase
{

    /**
     * @var LoopInterface
     */
    private $loop;

    /**
     * {@inheritDoc}
     */
    protected function setUp()
    {
        $this->loop = Factory::create();
    }

    /**
     * @return void
     */
    public function testCreatePool()
    {
        $pool = PoolFactory::createPool($this->loop, $this->getConnectionOptions(), 10);

        $this->assertInstanceOf('React\MySQL\Pool\PoolInterface', $pool);
        $this->assertEquals(10, count($pool));

        $pool = PoolFactory::createPool($this->loop, $this->getConnectionOptions(), '10');

        $this->assertInstanceOf('React\MySQL\Pool\PoolInterface', $pool);
        $this->assertEquals(10, count($pool));
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage $count should be greater then 0 but 0 given
     *
     * @return void
     */
    public function testCreatePoolWithInvalidNumberOfConnections()
    {
        PoolFactory::createPool($this->loop, $this->getConnectionOptions(), 0);
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage $count should be 'integer' but 'string' given
     *
     * @return void
     */
    public function testCreatePoolWithNotNumericString()
    {
        PoolFactory::createPool($this->loop, $this->getConnectionOptions(), '10a');
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage $count should be 'integer' but 'stdClass' given
     *
     * @return void
     */
    public function testCreatePoolWithInvalidTypeOfCount()
    {
        PoolFactory::createPool($this->loop, $this->getConnectionOptions(), new \stdClass());
    }
}
