<?php
require __DIR__ . '/init.php';

$loop = React\EventLoop\Factory::create();
$resolver = (new React\Dns\Resolver\Factory())->createCached('8.8.8.8', $loop);
$factory = new React\MySQL\Factory();
$client = $factory->create($loop, $resolver, []);
$promise = $client->auth([
	'user' => 'test',
	'password' => 'test',
	'dbname' => 'cspider',
]);
$promise->then(function ($options) use ($client){
	printf("------------- CONNECTED---------------\n");
	var_dump($options);
	
	$client->query('select * from link limit 2')
		->then(function ($data) use ($client){
			printf("------------ DATA --------------\n");
			var_dump($data);
			
			
		}, function ($reason) {
			printf("ERROR:%d %s\n", $reason->getCode(), $reason->getMessage());
		});
	
	
}, function ($reason) {
	printf("ERROR:%d %s\n", $reason->getCode(), $reason->getMessage());
});

$loop->addTimer(1, function () use ($client){
	$client->execute('USE `cspdider`')
		->then(function(){
			var_dump('success');
		}, function($reason){
			var_dump($reason->getMessage());
		});
});
$loop->addPeriodicTimer(2, function () use ($client){
	$client->ping()
		->then(function (){
			var_dump('ping success');
		}, function($reason){
			var_dump($reason->getMessage());
		});
});
$loop->run();
