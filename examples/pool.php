<?php
require __DIR__ . '/init.php';

// create the main loop
$loop = React\EventLoop\Factory::create();

// create pool with 10 connections
$pool = \React\MySQL\Pool\PoolFactory::createPool($loop, array(
    'dbname' => 'test',
    'user'   => 'test',
    'passwd' => 'test',
), 10);

// make any query to pool
$pool
    ->query('select * from book')
    ->then(function (\React\MySQL\Pool\PoolQueryResult $result) {
        $results = $result->getCmd()->resultRows; // get the results
        $fields = $result->getCmd()->resultFields; // get table fields
    })
    ->otherwise(function (\Exception $exception) {
        // handle exception.
    })
    ->always(function () use ($loop) {
        $loop->stop();
    });

$loop->run();
