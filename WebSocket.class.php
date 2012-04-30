<?php
require_once 'HandlerInterface.php';

class WebSocket
{
	private $address;
	private $port;
	private $_socket_array = array();
	private $_do_handshake = array();
	private static $_server_socket = NULL;
	
	/*
	 * @object
	 * handle the msg from the client
	 */
	public $handler = NULL;
	
	const CMD_CLOSE = 8; 
	const CMD_PING = 9;
	const CMD_PONG = 10;
	
	public function __construct($address = '127.0.0.1', $port = 12345)
	{
		$this->address = $address;
		$this->port = $port;
	}
	
	public function run($handler = NULL)
	{
		$this->_console('init...');
		$this->_init();
		$this->_console('init end');
		$this->set_handler($handler);
		while (TRUE)
		{
			$read = $this->_socket_array;
			$write = $this->_socket_array;
			$this->_console('have connected peer ' . (count($read) - 1));
			$this->_console('socket_select...');
			if (FALSE === socket_select($read, $wirte, $expect = NULL, NULL))
			{
				exit(socket_strerror(socket_last_error()));
			}
			$this->_console('socket_select end');
			$this->_console('readable socket:' . count($read));
			$this->_console('writeable socket:' . count($write));
			
			foreach ($read as $socket)
			{
				if ($socket == self::$_server_socket)
				{
					$this->_console('accept...');
					$client = socket_accept($socket);
					$this->_console('accept end');
					if ($client === false)
					{
						exit('socket_accept() failed');
					}
					else 
					{
						$id = uniqid();
						$this->_socket_array[$id] = $client;
						$this->_do_handshake[$id] = FALSE;
					}
				}
				else
				{
					$this->_console('socket_recv...');
					$len = socket_recv($socket, $buffer, 2048, 0);
					if ($len === FALSE)
					{
						exit(socket_strerror(socket_last_error()));
					}
					$this->_console('rcve length is:' . $len);
					$this->_console('socket_recv end');
					if ($len == 0)
					{
						$this->_console('close...');
						$this->_close($socket);
						$this->_console('close end');
					}
					else 
					{
						$id = array_search($socket, $this->_socket_array);
						$this->_console('socket id is ' . $id);
						if (! $this->_do_handshake[$id])
						{
							$this->_console('handshake...');
							$this->_do_handshake($socket, $buffer);
							$this->_console('handshake end');
						}
						else 
						{
							//var_dump($this->_unwrap($buffer));
							var_dump($this->_str2binary($buffer));
							$msg = $this->_unwrap($buffer);
							$reply = '';
							if (isset($msg['len']))
							{
								if (!empty($this->handler))
								{
									$reply = $this->handler->handle($msg);
								}
								$this->_send($socket, $reply);
							}
							else if (isset($msg['cmd']) && $msg['cmd'] == self::CMD_CLOSE)
							{
								$this->_send($socket, 'bye~');
								$this->_close($socket);
							}
						}
					}
				}
			}
		}
	}
	
	public function set_handler($handler = NULL)
	{
		if ($handler instanceof HandlerInterface)
		{
			$this->handler = $handler;
		}
	}
	
	private function _init()
	{
		if (self::$_server_socket)
		{
			return self::$_server_socket;
		}
		
		self::$_server_socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP) or die('socket_create() failed');
		socket_set_option(self::$_server_socket, SOL_SOCKET, SO_REUSEADDR, 1)  or die('socket_option() failed');
		socket_bind(self::$_server_socket, $this->address, $this->port) or die('socket_bind() failed');
		socket_listen(self::$_server_socket, 20);
		$this->_socket_array[uniqid()] = self::$_server_socket;
	}
	
	private function _do_handshake($socket, $buffer)
	{
		$request_header = $this->_get_request_header($buffer);
		$response_header = "HTTP/1.1 101 Web Socket Protocol Handshake\r\n" . 
							"Upgrade: websocket\r\n" . 
							"Connection: Upgrade\r\n" . 
							"Sec-WebSocket-Accept: " . $this->_generate_accept_key($request_header['sec_websocket_key']) . "\r\n" .
							"WebSocket-Origin: " . $request_header['origin'] . "\r\n" . 
							"WebSocket-Location: ws://" . $request_header['host'] . $request_header['path'] . "\r\n" . 
							"\r\n";
		if (socket_write($socket, $response_header, strlen($response_header)) !== strlen($response_header))
		{
			exit(socket_strerror(socket_last_error()));
		}
		$id = array_search($socket, $this->_socket_array);
		$this->_do_handshake[$id] = TRUE;
		return TRUE;
	}
	
	private function _send($socket, $msg)
	{
		$this->_console('send msg begin...');
		$msg = $this->_wrap($msg);
		$len = strlen($msg);
		
		if (socket_write($socket, $msg, $len) != $len)
		{
			$this->_console(socket_strerror(socket_last_error()));
			$this->_console('send msg failed.');
			return FALSE;
		}
		$this->_console('send msg end.');
		return TRUE;
	}
	
	private function _close($socket)
	{
		$this->_console('close socket beign...');
		$id = array_search($socket, $this->_socket_array);
		if ($id !== FALSE)
		{
			socket_close($socket);
			unset($this->_socket_array[$id]);
			unset($this->do_handshake[$id]);
		}
		$this->_console('close sokcet end.');
		return;
	}
	
	/**
	 * 根据Sec-WebSocket-Key的值生成Sec-WebSocket-Accept的值
	 * 具体生成规则如下：
	 * step1：将Sec-WebSocket-Key与字符串258EAFA5-E914-47DA-95CA-C5AB0DC85B11（一个标准的字符串）
	 * step2:将第一步生成的字符串进行sha1编码
	 * step3：将第二步生成的串每两个字符转换成十六进制数字对应的asc码，然后进行base64编码
	 */
	private function _generate_accept_key($key)
	{
		$key = $key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
		$key = sha1($key);
		$len = strlen($key);
		$str = '';
		for ($i = 0; $i < $len; $i+=2)
		{
			$str .= chr(hexdec($key[$i].$key[$i+1]));
		}
		return base64_encode($str);
	}
	
	private function _get_request_header($header)
	{
		if (preg_match("/GET (.*) HTTP/", $header, $matches))
		{
			$request_header['path'] = $matches[1];
		}
		if (preg_match("/Sec-WebSocket-Key: (.*)\r\n/", $header, $matches))
		{
			$request_header['sec_websocket_key'] = $matches[1];
		}
		if (preg_match("/Origin: (.*)\r\n/", $header, $matches))
		{
			$request_header['origin'] = $matches[1];
		}
		if (preg_match("/Host: (.*)\r\n/", $header, $matches))
		{
			$request_header['host'] = $matches[1];
		}
		return $request_header;
	}
	
	private function _console($msg)
	{
		echo '[info]' . $msg . "\n";
	}
	
	/**
	 * wrap msg to send to the client
	 */
	private function _wrap($msg)
	{
		$len = strlen($msg);
		$fin = '1';
		$rsv = '000';
		$opcode = '0001';
		$mask_bit = '0';
		
		if ($len < 126)
		{
			$len = str_pad(decbin($len), 7, '0', STR_PAD_LEFT);
		}
		else if ($len > 65536)
		{
			$len = '1111111' . str_pad(decbin($len), 64, '0', STR_PAD_LEFT);
		}
		else
		{
			$len = '1111110' . str_pad(decbin($len), 16, '0', STR_PAD_LEFT);
		}
		
		
		$data = $this->_str2binary($msg);
		$binary = $fin . $rsv . $opcode . $mask_bit . $len . $data;
		return $this->_binary2str($binary);
	}
	
	/**
	 * unwrap the msg from the client
	 */
	private function _unwrap($msg)
	{
		$binary = $this->_str2binary($msg);
		
		$fin = $binary[0]; //FIN, 1 bit(0), indicates if it is the final fragment
		$rsv = $binary[1] . $binary[2] . $binary[3]; //reserved bit
		$opcode = $binary[4] . $binary[5] . $binary[6] . $binary[7]; //opcode, 4 bit(4-7), define the type of the data
		$mask_bit = $binary[8]; //mask bit, 1 bit(8), indicates wheather the data is masked
		$len = bindec(substr($binary, 9, 7));//data length
		if ($len == 126)
		{//the following 2 bit interpreted as the length
			$len = bindec(substr($binary, 16, 16));
			$mask = substr($binary, 32, 32);
			$data = substr($binary, 64);
		}
		else if ($len == 127)
		{//the following 8 bit interpreted as the length
			$len = bindec(substr($binary, 16, 64));
			$mask = substr($binary, 80, 32);
			$data = substr($binary, 112);
		}
		else 
		{
			$mask = substr($binary, 16, 32);
			$data = substr($binary, 48);
		}

		if ($mask_bit == '1')
		{
			$temp = '';
			$mask_dec[0] = bindec(substr($mask, 0, 8)); 
			$mask_dec[1] = bindec(substr($mask, 8, 8));
			$mask_dec[2] = bindec(substr($mask, 16, 8));
			$mask_dec[3] = bindec(substr($mask, 24));
			for ($i = 0; $i < $len; $i++)
			{
				$temp .= chr(bindec(substr($data, $i * 8, 8)) ^ $mask_dec[$i % 4]);
				//$temp = decbin(bindec(substr($data, $i * 8, 8)) ^ $mask_dec[$i % 4]);
				//var_dump($temp);
			}
			$data = $temp;
		}
		
		/**
		 * check if is a final fragment
		 */
		if ($fin == '1')
		{
			switch ($opcode)
			{
				case '0001' : //text frame
				case '0002' : // binary frame
					return array('msg' => $data, 'len' => $len);
				case '1000' :
					return array('cmd' => self::CMD_CLOSE);
				case '1001' :
					return array('cmd' => self::CDM_PING);
				case '1010' :
					return array('cmd' => self::CMD_PONG);
				default :
					break;
			}
		}
		else 
		{
		}
	}
	
	private function _str2binary($str)
	{
		$binary = '';
		$len = strlen($str);
		for ($i = 0; $i < $len; $i++)
		{
			$binary .= str_pad(decbin(ord($str[$i])), 8, '0', STR_PAD_LEFT);
		}
		return $binary;
	}
	
	private function _binary2str($binary)
	{
		$str = '';
		while (!empty($binary))
		{
			$str .= chr(bindec(substr($binary, 0, 8)));
			$binary = substr($binary, 8);
		}
		return $str;
	}
}