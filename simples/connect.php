<?php
require __DIR__ . '/init.php';

$loop = React\EventLoop\Factory::create();
$resolver = (new React\Dns\Resolver\Factory())->createCached('8.8.8.8', $loop);
$factory = new React\MySQL\Factory();
$client = $factory->create($loop, $resolver, []);
$client->auth('test', 'test')
	->on('data', function ($data) {
		var_dump($data);
	});

$loop->run();
