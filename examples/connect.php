<?php
require __DIR__ . '/init.php';

$loop = React\EventLoop\Factory::create();
$resolver = (new React\Dns\Resolver\Factory())->createCached('8.8.8.8', $loop);
$factory = new React\MySQL\Factory();
$client = $factory->create($loop, $resolver, []);
$promise = $client->auth([
	'user' => 'root',
	'password' => 'sdyxzsdyxz',
	'dbname' => 'cspider',
]);
$promise->then(function ($options){
	var_dump($options);
}, function ($error) {
	var_dump($error->getMessage());
});
$client->query('USE `test`')
	->then(function ($data) {
		var_dump($data);
	}, function ($reason) {
		var_dump($reason);
	});

$loop->run();
