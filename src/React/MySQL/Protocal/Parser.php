<?php

namespace React\MySQL\Protocal;


use Evenement\EventEmitter;
use React\MySQL\Exception;

class Parser extends EventEmitter{
	
	const PHASE_GOT_INIT   = 1;
	const PHASE_AUTH_SENT  = 2;
	const PHASE_AUTH_ERR   = 3;
	const PHASE_HANDSHAKED = 4;
	
	const RS_STATE_HEADER = 0;
	const RS_STATE_FIELD  = 1;
	const RS_STATE_ROW    = 2;
	
	const STATE_STANDBY = 0;
	const STATE_BODY    = 1;
	
	protected $user     = 'root';
	protected $password = '';
	protected $dbname   = '';
	
	protected $callback;
	
	protected $state = 0;
	
	protected $phase = 0;
	
	public $seq = 0;
	public $clientFlags = 239237;
	
	public $warnCount;
	public $message;
	
	protected $maxPacketSize = 0x1000000;
	
	public $charsetNumber = 0x21;
	
	protected $serverVersion;
	protected $threadId;
	protected $scramble;
	
	protected $serverCaps;
	protected $serverLang;
	protected $serverStatus;
	
	protected $rsState = 0;
	protected $pctSize = 0;
	protected $resultRows = [];
	protected $resultFields = [];
	
	protected $insertId;
	protected $affectedRows;
	
	public $protocalVersion = 0;
	
	protected $errno = 0;
	protected $errmsg = '';
	
	protected $buffer = '';
	protected $bufferParsed = 0;
	
	protected $connectOptions;
	
	/**
	 * @var React\Stream\Stream
	 */
	protected $stream;
	
	public function __construct($stream) {
		$this->stream = $stream;
		$stream->on('data', array($this, 'parse'));
	}

	public function setOptions($options) {
		foreach ($options as $option => $value) {
			if (property_exists($this, $option)) {
				$this->$option = $value;
			}
		}
	}
	
	
	public function parse($data, $stream) {
		$this->buffer .= $data;
		//var_dump($data);
packet:
		if ($this->state === self::STATE_STANDBY) {
			if ($this->getBufferLen() < 4) {
				return;
			}
			
			$this->pctSize = Binary::bytes2int($this->readBuffer(3), true);
			printf("packet size:%d\n", $this->pctSize);
			$this->state = self::STATE_BODY;
			$this->seq = ord($this->readBuffer(1)) + 1;
		}
		
		$len = $this->getBufferLen();
		if ($len < $this->pctSize) {
			printf("Buffer not enouth, return\n");
			return;
		}
		$this->state = self::STATE_STANDBY;
		//$this->stream->bufferSize = 4;
		if ($this->phase === 0) {
			$this->phase = self::PHASE_GOT_INIT;
			$this->protocalVersion = ord($this->readBuffer(1));
			printf("Protocal Version: %d\n", $this->protocalVersion);
			if ($this->protocalVersion === 0xFF) { //error
				$fieldCount = $this->protocalVersion;
				printf("Error:\n");
				
				$this->rsState = self::RS_STATE_HEADER;
				$this->resultFields = [];
				$this->resultRows = [];
				if ($this->phase === self::PHASE_AUTH_SENT || $this->phase === self::PHASE_GOT_INIT) {
					$this->phase = self::PHASE_AUTH_ERR;
				}
				
				goto field;
			}
			if (($p = $this->bufferSearch("\x00")) === false) {
				printf("Finish\n");
				//finish
				return;
			}
			
			$options = &$this->connectOptions;
			
			$options['serverVersion'] = $this->readBuffer($p, 1);
			$options['threadId']      = Binary::bytes2int($this->readBuffer(4), true);
			$this->scramble           = $this->readBuffer(8, 1);
			$options['ServerCaps']    = Binary::bytes2int($this->readBuffer(2), true); 
			$options['serverLang']    = ord($this->readBuffer(1));
			$options['serverStatus']  = Binary::bytes2int($this->readBuffer(2, 13), true);
			$restScramble             = $this->readBuffer(12, 1);
			$this->scramble          .= $restScramble;
			
			$this->auth();
		}else {
			$fieldCount = ord($this->readBuffer(1));
field:
			if ($fieldCount === 0xFF) { 
				//error packet
				$u             = unpack('v', $this->readBuffer(2));
				$this->errno   = $u[1];
				$state = $this->readBuffer(6);
				$this->errmsg  = $this->readBuffer($this->pctSize - $len + $this->getBufferLen());
				printf("Error Packet:%d %s\n", $this->errno, $this->errmsg);
				$this->onError();
				
				
			}elseif ($fieldCount === 0x00) { //OK Packet Empty
				printf("Ok Packet\n");
				if ($this->phase === self::PHASE_AUTH_SENT) {
					$this->phase = self::PHASE_HANDSHAKED;
					if ($this->dbname != '') {
						$this->query(sprintf('USE `%s`', $this->dbname), function (){
							$this->emit('connected', array($this->connectOptions));
						});
					}else {
						$this->emit('connected', array($this->connectOptions));
					}
				}
				if ($this->callback) {
					call_user_func($this->callback, 'SUCCESS: Ok packet');
				}
				
				$this->affectedRows = $this->parseEncodedBinary();
				$this->insertId     = $this->parseEncodedBinary();
				
				$u                  = unpack('v', $this->readBuffer(2));
				$this->serverStatus = $u[1];
				
				$u                  = unpack('v', $this->readBuffer(2));
				$this->warnCount    = $u[1];
				
				$this->message      = $this->readBuffer($this->pctSize - $len + $this->getBufferLen());
				
				$this->onSuccess();
				
			}elseif ($fieldCount === 0xFE) { //EOF Packet
				printf("EOF Packet\n");
				if ($this->rsState === self::RS_STATE_ROW) {
					printf("result done\n");
					
					$this->onResultDone();
				}else {
					++ $this->rsState;
				}
			}else { //Data packet
				printf("Data Packet\n");
				
				$this->prependInput(chr($fieldCount));
				
				if ($this->rsState === self::RS_STATE_HEADER) {
					printf("Header packet of Data packet\n");
					$extra = $this->parseEncodedBinary();
					//var_dump($extra);
					$this->rsState = self::RS_STATE_FIELD;
				}elseif ($this->rsState === self::RS_STATE_FIELD) {
					printf("Field packet of Data packet\n");
					$field = [
						'catalog'   => $this->parseEncodedString(),
						'db'        => $this->parseEncodedString(),
						'table'     => $this->parseEncodedString(),
						'org_table' => $this->parseEncodedString(),
						'name'      => $this->parseEncodedString(),
						'org_name'  => $this->parseEncodedString()
					];

					$this->readBuffer(1);
					$u                    = unpack('v', $this->readBuffer(2));
					$field['charset']     = $u[1];
					
					$u                    = unpack('v', $this->readBuffer(4));
					$field['length']      = $u[1];
					
					$u                    = unpack('v', $this->readBuffer(2));
					$field['flags']       = $u[1];
					$field['decimals']    = ord($this->readBuffer(1));
					var_dump($field);
					$this->resultFields[] = $field;
					
				}elseif ($this->rsState === self::RS_STATE_ROW) {
					printf("Row packet of Data packet\n");
					$row = [];
					for ($i = 0, $nf = sizeof($this->resultFields); $i < $nf; ++$i) {
						$row[$this->resultFields[$i]['name']] = $this->parseEncodedString();
					}
					$this->resultRows[] = $row;
				}
			}
		}
		$this->readBuffer($this->pctSize - $len + $this->getBufferLen());
		goto packet;
	}
	
	protected function onError() {
		$this->emit('error', [new Exception($this->errmsg, $this->errno)]);
		$this->errmsg = '';
		$this->errno  = 0;
	}
	
	protected function onResultDone() {
		//var_dump($this->listeners('results'));
		$this->emit('results', array($this->resultRows));
		$this->rsState      = self::RS_STATE_HEADER;
		$this->resultRows   = $this->resultRows = [];
	}
	
	
	protected function onSuccess() {
		$this->emit('success');
	}
	
	public function readBuffer($len, $emit = 0) {
		$buffer = substr($this->buffer, $this->bufferParsed, $len);
		$this->bufferParsed += $len;
		if ($emit) {
			$this->bufferParsed += $emit;
		}
		return $buffer;
	}
	
	public function getBufferLen() {
		return strlen($this->buffer) - $this->bufferParsed ;
	}
	
	public function bufferSearch($what) {
		if (($p = strpos($this->buffer, $what, $this->bufferParsed)) !== false) {
			
			return $p - $this->bufferParsed;
		}
		return false;
	}
	
	public function prependInput($str) {
		$this->buffer = $str . substr($this->buffer, $this->bufferParsed);
		$this->bufferParsed = 0;
	}
	
	public function auth() {
		if ($this->phase !== self::PHASE_GOT_INIT) {
			return;
		}
		$this->phase = self::PHASE_AUTH_SENT;
		
		$clientFlags = Constants::CLIENT_LONG_PASSWORD |
				Constants::CLIENT_LONG_FLAG |
				Constants::CLIENT_LOCAL_FILES |
				Constants::CLIENT_PROTOCOL_41 |
				Constants::CLIENT_INTERACTIVE |
				Constants::CLIENT_TRANSACTIONS |
				Constants::CLIENT_SECURE_CONNECTION |
				Constants::CLIENT_MULTI_RESULTS |
				Constants::CLIENT_MULTI_STATEMENTS;
		
		
		$packet = pack('VVc', $clientFlags, $this->maxPacketSize, $this->charsetNumber)
				. "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00"
				. $this->user . "\x00"
				. $this->getAuthToken($this->scramble, $this->password)
				. '';
		
		$this->sentPacket($packet);
		printf("Auth packet sent\n");
	}
	
	public function getAuthToken($scramble, $password = '') {
		if ($password === '') {
			return "\x00";
		}
		$token = sha1($scramble . sha1($hash1 = sha1($password, true), true), true) ^ $hash1;
		return $this->buildLenEncodedBinary($token);
	}
	
	/**
	 * Builds length-encoded binary string
	 * @param string String
	 * @return string Resulting binary string
	 */
	public function buildLenEncodedBinary($s) {
		if ($s === NULL) {
			return "\251";
		}
	
		$l = strlen($s);
	
		if ($l <= 250) {
			return chr($l) . $s;
		}
	
		if ($l <= 0xFFFF) {
			return "\252" . Binary::int2bytes(2, true) . $s;
		}
	
		if ($l <= 0xFFFFFF) {
			return "\254" . Binary::int2bytes(3, true) . $s;
		}
	
		return Binary::int2bytes(8, $l, true) . $s;
	}
	
	/**
	 * Parses length-encoded binary integer
	 * @return integer Result
	 */
	public function parseEncodedBinary() {
		$f = ord($this->readBuffer(1));
		if ($f <= 250) {
			return $f;
		}
		if ($f === 251) {
			return null;
		}
		if ($f === 255) {
			return false;
		}
		if ($f === 252) {
			return Binary::bytes2int($this->readBuffer(2), true);
		}
		if ($f === 253) {
			return Binary::bytes2int($this->readBuffer(3), true);
		}
		return Binary::bytes2int($this->readBuffer(8), true);
	}
	
	/**
	 * Parse length-encoded string
	 * @return integer Result
	 */
	public function parseEncodedString() {
		$l = $this->parseEncodedBinary();
		if (($l === null) || ($l === false)) {
			return $l;
		}
		return $this->readBuffer($l);
	}
	
	public function sentPacket($packet) {
		return $this->stream->write(Binary::int2bytes(3, strlen($packet), true) . chr($this->seq++) . $packet);
	}
	
	public function query($sql, $callback = null) {
		return $this->command(Constants::COM_QUERY, $sql, $callback);
	}
	
	public function command($cmd, $q = '', $callback = null) {
		if ($this->phase != self::PHASE_HANDSHAKED) {
			return false;
		}
		$this->callback = $callback;
		$this->seq = 0;
		$this->sentPacket(chr($cmd) . $q);
		return true;
	}
}
