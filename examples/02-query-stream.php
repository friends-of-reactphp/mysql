<?php

use React\MySQL\ConnectionInterface;
use React\MySQL\Factory;

require __DIR__ . '/../vendor/autoload.php';

$loop = React\EventLoop\Factory::create();
$factory = new Factory($loop);

$uri = 'test:test@localhost/test';
$query = isset($argv[1]) ? $argv[1] : 'select * from book';

//create a mysql connection for executing query
$factory->createConnection($uri)->then(function (ConnectionInterface $connection) use ($query) {
    $stream = $connection->queryStream($query);
    $stream->on('data', function ($row) {
        var_dump($row);
    });

    $stream->on('error', function (Exception $e) {
        echo 'Error: ' . $e->getMessage() . PHP_EOL;
    });

    $stream->on('close', function () {
        echo 'CLOSED' . PHP_EOL;
    });

    $connection->quit();
}, 'printf');

$loop->run();
