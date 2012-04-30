<?php 
require_once 'HandlerInterface.php';

class MyHandler implements HandlerInterface
{
	public function __construct() {}
	
	public function handle($msg)
	{
		var_dump($msg['msg']);
		return 'msg from server:' . $msg['msg'];
	}
}