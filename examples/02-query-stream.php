<?php

require __DIR__ . '/../vendor/autoload.php';

//create the main loop
$loop = React\EventLoop\Factory::create();

//create a mysql connection for executing queries
$connection = new React\MySQL\Connection($loop, array(
    'dbname' => 'test',
    'user'   => 'test',
    'passwd' => 'test',
));

$connection->connect(function () {});

$sql = isset($argv[1]) ? $argv[1] : 'select * from book';

$stream = $connection->queryStream($sql);
$stream->on('data', function ($row) {
    var_dump($row);
});

$stream->on('error', function (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
});

$stream->on('close', function () {
    echo 'CLOSED' . PHP_EOL;
});

$connection->close();

$loop->run();
