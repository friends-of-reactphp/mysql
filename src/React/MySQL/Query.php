<?php

namespace React\MySQL;

class Query {
	
	private $sql;
	
	private $params;

	private $escapeChars = array(
			"\0"   => "\\0",
			"\r"   => "\\r",
			"\n"   => "\\n",
			"\t"   => "\\t",
			"\b"   => "\\b",
			"\x1a" => "\\Z"
		);
	
	public function __construct($sql) {
		$this->sql = $sql;
	}
	
	/**
	 * Binding params for the query, mutiple arguments support.
	 * 
	 * @param mixed $param
	 * @return \React\MySQL\Query
	 */
	public function params() {
		$this->params = func_get_args();
		return $this;
	}
	
	public function escape($str) {
		return strtr($str, array($this->escapeChars));
	}
	
	protected function getEscapedStringAndLen($val) {
		if (is_string($val)) {
			$val = "'" . $this->escape($val) . "'";
		}
		return array($val, strlen($val));
	}
	
	/**
	 * Get the constructed and escaped sql string.
	 * 
	 * @return string
	 */
	public function getSql() {
		$sql = $this->sql;
		$pos = strpos($sql, '?');
		foreach ($this->params as $arg) {
			list($arg, $len) = $this->getEscapedStringAndLen($arg);
			$sql = substr_replace($sql, $arg, $pos, 1);
			$pos = strpos($sql, '?', $pos + $len);
		}
		return $sql;
	}
}
