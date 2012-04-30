<?php
require 'WebSocket.class.php';
require 'MyHandler.php';

$web_socket = new WebSocket();
$web_socket->run(new MyHandler());
