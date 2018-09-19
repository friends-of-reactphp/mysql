<?php

use React\MySQL\Factory;
use React\MySQL\QueryResult;

require __DIR__ . '/../vendor/autoload.php';

$loop = React\EventLoop\Factory::create();
$factory = new Factory($loop);

$uri = 'test:test@localhost/test';
$query = isset($argv[1]) ? $argv[1] : 'select * from book';

//create a lazy mysql connection for executing query
$connection = $factory->createLazyConnection($uri);

$connection->query($query)->then(function (QueryResult $command) {
    if (isset($command->resultRows)) {
        // this is a response to a SELECT etc. with some rows (0+)
        print_r($command->resultFields);
        print_r($command->resultRows);
        echo count($command->resultRows) . ' row(s) in set' . PHP_EOL;
    } else {
        // this is an OK message in response to an UPDATE etc.
        if ($command->insertId !== 0) {
            var_dump('last insert ID', $command->insertId);
        }
        echo 'Query OK, ' . $command->affectedRows . ' row(s) affected' . PHP_EOL;
    }
}, function (Exception $error) {
    // the query was not executed successfully
    echo 'Error: ' . $error->getMessage() . PHP_EOL;
});

$connection->quit();

$loop->run();
