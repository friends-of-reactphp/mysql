reactphp-mysql
===============

## Install

The recommended way to install reactphp-mysql is through [composer](http://getcomposer.org).

```
{
    "require": {
        "react/mysql": "0.2.*"
    }
}
```

## Introduction	

This is a mysql driver for [reactphp](https://github.com/reactphp/react), It is written 
in pure PHP, implemented the mysql protocol.

See examples for usage details.

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

## Thanks

Thanks to the following projects.

* [phpdaemon](https://github.com/kakserpom/phpdaemon): the mysql protocol implemention based some code of the project.
* [node-mysql](https://github.com/felixge/node-mysql): take some inspirations from this project for API design.

