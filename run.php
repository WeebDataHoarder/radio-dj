<?php

declare(strict_types=1);

require_once "vendor/autoload.php";

require_once "config.php";

ini_set("memory_limit", "-1");

setlocale(LC_CTYPE, "en_US.UTF-8");

$loop = React\EventLoop\Factory::create();

$database = new \Dj\Database($loop,DB_HOST, DB_PORT, DB_USER, DB_PASSWORD, DB_MUSIC_NAME);
$api = new \Dj\API($loop, "http://" . HOST_API, DEFAULT_API_KEY);
$selector = new \Dj\RandomSelector($loop, $database, $api);
$requestHandler = new \Dj\RequestHandler($selector);

$server = new \React\Http\Server($loop, [$requestHandler, "handleRequest"]);

$socket = new React\Socket\Server("0.0.0.0:8080", $loop);
$server->listen($socket);


$loop->run();