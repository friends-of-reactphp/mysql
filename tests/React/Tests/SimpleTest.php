<?php
namespace React\Tests;


class SimpleTest extends \PHPUnit_Framework_TestCase {
	
	private $loop;
	private $resolver;
	private $mysql;
	
	public function setUp() {
		$this->loop = \React\EventLoop\Factory::create();
		$this->resolver = (new \React\Dns\Resolver\Factory())->createCached('8.8.8.8', $this->loop);
	}
	
	protected function getClient() {
		if (!$this->mysql) {
			$this->mysql = (new \React\MySQL\Factory())->create($this->loop, $this->resolver, []);
		}
		return $this->mysql;
	}
	
	
	public function testConnectionRefused() {
		
	}
	
	public function testAuth() {
		$client = $this->getClient();
		$request = $client->auth('root', 'sdyxzsdyxz');
		$request->on('data', function ($data) {
			printf("auth successed\n");
		});
		$request->on('error', function ($error) {
			printf("Error: %s\n", $error->getMessage());
		});
		$this->loop->run();
	}
	
	public function ftestHttpRequest() {
		$loop = \React\EventLoop\Factory::create();
		$resolver = (new \React\Dns\Resolver\Factory())->createCached('8.8.8.8', $loop);
		$client = (new \React\HttpClient\Factory())->create($loop, $resolver);
		$request = $client->request('GET', 'http://www.baidu.com');
		$request->on('response', function ($response) {
			$response->on('data', function ($data) {
				var_dump($data);
			});
		});
		$request->end();
		$loop->run();
	}
}
