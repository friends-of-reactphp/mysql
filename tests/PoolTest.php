<?php

namespace React\Tests\MySQL;

use React\EventLoop\Factory;
use React\EventLoop\LoopInterface;
use React\MySQL\Connection;
use React\MySQL\ConnectionInterface;
use React\MySQL\Pool\Pool;
use React\Promise\Deferred;

class PoolTest extends BaseTestCase
{

    const COUNT = 4;

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

        //
        // Make sure that this test will not block phpunit.
        //
        $this->loop->addTimer(3, function () {
            $this->assertTrue(false, 'Timer callback should not be called');
            $this->loop->stop();
        });
    }

    /**
     * @return void
     */
    public function testCreatePool()
    {
        $pool = new Pool([ new Connection($this->loop, $this->getConnectionOptions()) ]);

        $this->assertCount(1, $pool);
    }

    /**
     * @return void
     */
    public function testCreatePoolWithInitializedConnection()
    {
        $conn = new Connection($this->loop, $this->getConnectionOptions());

        $conn->connect(function (\Exception $exception = null, ConnectionInterface $connection) {
            $pool = new Pool([ $connection ]);

            $this->assertNull($exception);
            $this->assertCount(1, $pool);

            $pool
                ->getConnection()
                ->then(function (ConnectionInterface $conn) use ($connection) {
                    $this->assertSame($connection, $conn);

                    $this->loop->stop();
                })
                ->done();
        });

        $this->loop->run();
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage Should be at least one connection in pool
     *
     * @return void
     */
    public function testCreatePoolWithoutConnections()
    {
        new Pool([]);
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage Each passed connection should implements 'React\MySQL\ConnectionInterface' but one of connections is 'stdClass'
     *
     * @return void
     */
    public function testCreatePoolWithInvalidConnectionInstance()
    {
        new Pool([
            new Connection($this->loop, $this->getConnectionOptions()),
            new \stdClass()
        ]);
    }

    /**
     * @return void
     */
    public function testGetConnection()
    {
        $options = $this->getConnectionOptions();

        $pool = new Pool([ new Connection($this->loop, $options) ]);

        $promise = $pool->getConnection();
        $this->assertInstanceOf('React\Promise\Promise', $promise);
        $promise
            ->then(function ($conn) use ($options) {
                /** @var ConnectionInterface $conn */
                $this->assertInstanceOf('React\MySQL\ConnectionInterface', $conn);

                $this->assertEquals($options['dbname'], $conn->getOption('dbname'));
                $this->assertEquals($options['user'], $conn->getOption('user'));
                $this->assertEquals($options['passwd'], $conn->getOption('passwd'));
                $this->assertEquals(ConnectionInterface::STATE_AUTHENTICATED, $conn->getState());
                $this->loop->stop();
            })
            ->done();

        $this->loop->run();
    }

    /**
     * @return void
     */
    public function testGetConnectionReconnectAfterClosing()
    {
        $originalConn = new Connection($this->loop, $this->getConnectionOptions());
        $pool = new Pool([ $originalConn ]);

        $pool->getConnection()
            ->then(function (ConnectionInterface $conn) use ($originalConn) {
                $deferred = new Deferred();

                $conn->close(function (ConnectionInterface $conn) use ($deferred) {
                    $deferred->resolve($conn);
                });

                $this->assertSame($originalConn, $conn);

                return $deferred->promise();
            })
            ->then(function (ConnectionInterface $conn) use ($pool, $originalConn) {
                $this->assertSame($originalConn, $conn);
                $this->assertCount(1, $pool);
                $this->assertEquals(ConnectionInterface::STATE_CLOSED, $conn->getState());

                return $pool->getConnection();
            })
            ->then(function (ConnectionInterface $conn) use ($originalConn) {
                $this->assertSame($originalConn, $conn);
                $this->assertEquals(ConnectionInterface::STATE_AUTHENTICATED, $conn->getState());
                $this->loop->stop();
            })
            ->done();

        $this->loop->run();
    }

    /**
     * @return void
     */
    public function testGetConnectionSelectingFromPool()
    {
        $conn1 = new Connection($this->loop, $this->getConnectionOptions());
        $conn2 = new Connection($this->loop, $this->getConnectionOptions());

        $pool = new Pool([ $conn1, $conn2 ]);

        $pool
            ->getConnection()
            ->then(function (ConnectionInterface $conn) use ($conn1, $pool) {
                $this->assertSame($conn1, $conn);
                $this->assertEquals(ConnectionInterface::STATE_AUTHENTICATED, $conn->getState());

                return $pool->getConnection();
            })
            ->then(function (ConnectionInterface $conn) use ($conn2, $pool) {
                $this->assertSame($conn2, $conn);
                $this->assertEquals(ConnectionInterface::STATE_AUTHENTICATED, $conn->getState());

                return $pool->getConnection();
            })
            ->then(function (ConnectionInterface $conn) use ($conn1) {
                $this->assertSame($conn1, $conn);
                $this->assertEquals(ConnectionInterface::STATE_AUTHENTICATED, $conn->getState());
            })
            ->done(function () {
                $this->loop->stop();
            });


        $this->loop->run();
    }
}
