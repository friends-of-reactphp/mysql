# MySQL

Async, [Promise](https://github.com/reactphp/promise)-based MySQL database client
for [ReactPHP](https://reactphp.org/).

This is a MySQL database driver for [ReactPHP](https://reactphp.org/).
It implements the MySQL protocol and allows you to access your existing MySQL
database.
It is written in pure PHP and does not require any extensions.

## Usage

### Connection

The `Connection` is responsible for communicating with your MySQL server
instance, managing the connection state and sending your database queries.
It also registers everything with the main [`EventLoop`](https://github.com/reactphp/event-loop#usage).

```php
$loop = React\EventLoop\Factory::create();

$options = array(
    'host'   => '127.0.0.1',
    'port'   => 3306,
    'user'   => 'root',
    'passwd' => '',
    'dbname' => '',
);

$connection = new Connection($loop, $options);
```

If you need custom connector settings (DNS resolution, TLS parameters, timeouts,
proxy servers etc.), you can explicitly pass a custom instance of the
[`ConnectorInterface`](https://github.com/reactphp/socket#connectorinterface):

```php
$connector = new \React\Socket\Connector($loop, array(
    'dns' => '127.0.0.1',
    'tcp' => array(
        'bindto' => '192.168.10.1:0'
    ),
    'tls' => array(
        'verify_peer' => false,
        'verify_peer_name' => false
    )
));

$connection = new Connection($loop, $options, $connector);
```

## Install

The recommended way to install this library is [through Composer](https://getcomposer.org).
[New to Composer?](https://getcomposer.org/doc/00-intro.md)

This will install the latest supported version:

```bash
$ composer require react/mysql:^0.2
```

## License

MIT, see [LICENSE file](LICENSE).

This is a community project now managed by
[@friends-of-reactphp](https://github.com/friends-of-reactphp).
The original implementation was created by
[@bixuehujin](https://github.com/bixuehujin) starting in 2013 and has been
migrated to [@friends-of-reactphp](https://github.com/friends-of-reactphp) in
2018 to help with maintenance and upcoming feature development.

The original implementation was made possible thanks to the following projects:

* [phpdaemon](https://github.com/kakserpom/phpdaemon): the MySQL protocol
  implementation is based on code of this project (with permission).
* [node-mysql](https://github.com/felixge/node-mysql): the API design is
  inspired by this project.
