<?php

define("HOST", "0.0.0.0");
require __DIR__ . '/../../vendor/autoload.php';

$server = new \App\WebSocket\WebSocketServer();

$server->run();