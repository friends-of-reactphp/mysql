<?php

// $ php examples/13-pool.php
// $ MYSQL_URI=test:test@localhost/test php examples/13-pool.php "SELECT * FROM book" 


use React\MySQL\Pool;
use React\MySQL\QueryResult;
use React\EventLoop\Loop;

require __DIR__ . '/../vendor/autoload.php';

$pool = new Pool(getenv('MYSQL_URI') ?: 'test:test@localhost/test', [
    'max_connections' => 10, // 10 connection
    'max_wait_queue' => 300, // how many sql in queue
    'wait_timeout' => 5,// wait time include response time
]);

$query = isset($argv[1]) ? $argv[1] : 'select * from book';

poolQuery($pool, $query);
poolQueryStream($pool, $query);

function poolQuery($pool, $query) {
    for ($i=0; $i < 90; $i++) { 
        $pool->query($query)->then(function (QueryResult $command) use ($i) {
            echo "query:$i\n";
            if (isset($command->resultRows)) {
                // this is a response to a SELECT etc. with some rows (0+)
                // print_r($command->resultFields);
                // print_r($command->resultRows);
                echo count($command->resultRows) . ' row(s) in set' . PHP_EOL;
            } else {
                // this is an OK message in response to an UPDATE etc.
                if ($command->insertId !== 0) {
                    var_dump('last insert ID', $command->insertId);
                }
                echo 'Query OK, ' . $command->affectedRows . ' row(s) affected' . PHP_EOL;
            }
        }, function (\Exception $error) {
            // the query was not executed successfully
            echo 'Error: ' . $error->getMessage() . PHP_EOL;
        });
        
    }
}

function poolQueryStream($pool, $query){
    for ($i=0; $i < 90; $i++) { 
        $stream = $pool->queryStream($query);
        $stream->on('data', function ($row) use ($i) {
            // echo "queryStream:$i\n";
            // print_r($row);
        });
        $stream->on('error', function ($err) {
            echo 'Error: ' . $err->getMessage() . PHP_EOL;
        });
        $stream->on('end', function () use ($i) {
            echo 'Completed:'.$i . PHP_EOL;
        });
    }
}

Loop::addPeriodicTimer(5, function() use ($pool, $query) {
    poolQuery($pool, $query);
    poolQueryStream($pool, $query);
});