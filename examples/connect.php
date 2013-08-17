<?php
require __DIR__ . '/init.php';

$loop = React\EventLoop\Factory::create();

$connection = new React\MySQL\Connection($loop, array(
	'dbname' => 'test',
	'user'   => 'test',
	'passwd' => 'test',
));

$connection->connect(function (){});
$connection->query('select * from book', function ($err, $rows, $conn) {
	var_dump($rows);
});
$loop->run();
