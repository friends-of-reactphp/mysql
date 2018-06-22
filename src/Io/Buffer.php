<?php
namespace React\MySQL\Io;

/**
 * @internal
 */
class Buffer
{
    private $buffer = '';
    private $bufferPos = 0;

    /**
     * appends some data to the end of the buffer without moving buffer position
     *
     * @param string $str
     * @return void
     */
    public function append($str)
    {
        $this->buffer .= $str;
    }

    /**
     * prepends some data to start of buffer and resets buffer position to start
     *
     * @param string $str
     * @return void
     */
    public function prepend($str)
    {
        $this->buffer = $str . \substr($this->buffer, $this->bufferPos);
        $this->bufferPos = 0;
    }

    /**
     * Reads binary string data with given byte length from buffer
     *
     * @param int $len length in bytes, must be positive or zero
     * @return string
     * @throws \LogicException
     */
    public function read($len)
    {
        // happy path to return empty string for zero length string
        if ($len === 0) {
            return '';
        }

        // happy path for single byte strings without using substrings
        if ($len === 1 && isset($this->buffer[$this->bufferPos])) {
            return $this->buffer[$this->bufferPos++];
        }

        // ensure buffer size contains $len bytes by checking target buffer position
        if ($len < 0 || !isset($this->buffer[$this->bufferPos + $len - 1])) {
            throw new \LogicException('Not enough data in buffer to read ' . $len . ' bytes');
        }
        $buffer = \substr($this->buffer, $this->bufferPos, $len);
        $this->bufferPos += $len;

        return $buffer;
    }

    /**
     * Skips binary string data with given byte length from buffer
     *
     * This method can be used instead of `read()` if you do not care about the
     * bytes that will be skipped.
     *
     * @param int $len length in bytes, must be positve and non-zero
     * @return void
     * @throws \LogicException
     */
    public function skip($len)
    {
        if ($len < 1 || !isset($this->buffer[$this->bufferPos + $len - 1])) {
            throw new \LogicException('Not enough data in buffer');
        }
        $this->bufferPos += $len;
    }

    /**
     * Clears all consumed data from the buffer
     *
     * This class keeps consumed data in memory for performance reasons and only
     * advances the internal buffer position until this method is called.
     *
     * @return void
     */
    public function trim()
    {
        if (!isset($this->buffer[$this->bufferPos])) {
            $this->buffer = '';
        } else {
            $this->buffer = \substr($this->buffer, $this->bufferPos);
        }
        $this->bufferPos = 0;
    }

    /**
     * returns the buffer length measures in number of bytes
     *
     * @return int
     */
    public function length()
    {
        return \strlen($this->buffer) - $this->bufferPos;
    }

    /**
     * @return int 1 byte / 8 bit integer (0 to 255)
     */
    public function readInt1()
    {
        return \ord($this->read(1));
    }

    /**
     * @return int 2 byte / 16 bit integer (0 to 64 K / 0xFFFF)
     */
    public function readInt2()
    {
        $v = \unpack('v', $this->read(2));
        return $v[1];
    }

    /**
     * @return int 3 byte / 24 bit integer (0 to 16 M / 0xFFFFFF)
     */
    public function readInt3()
    {
        $v = \unpack('V', $this->read(3) . "\0");
        return $v[1];
    }

    /**
     * @return int 4 byte / 32 bit integer (0 to 4 G / 0xFFFFFFFF)
     */
    public function readInt4()
    {
        $v = \unpack('V', $this->read(4));
        return $v[1];
    }

    /**
     * @return int 8 byte / 64 bit integer (0 to 2^64-1)
     * @codeCoverageIgnore
     */
    public function readInt8()
    {
        // PHP < 5.6.3 does not support packing 64 bit ints, so use manual bit shifting
        if (\PHP_VERSION_ID < 50603) {
            $v = \unpack('V*', $this->read(8));
            return $v[1] + ($v[2] << 32);
        }

        $v = \unpack('P', $this->read(8));
        return $v[1];
    }

    /**
     * Parses length-encoded binary integer
     *
     * @return int|null decoded integer 0 to 2^64 or null for special null int
     */
    public function readIntLen()
    {
        $f = $this->readInt1();
        if ($f <= 250) {
            return $f;
        }
        if ($f === 251) {
            return null;
        }
        if ($f === 252) {
            return $this->readInt2();
        }
        if ($f === 253) {
            return $this->readInt3();
        }

        return $this->readInt8();
    }

    /**
     * Parses length-encoded binary string
     *
     * @return string|null decoded string or null if length indicates null
     */
    public function readStringLen()
    {
        $l = $this->readIntLen();
        if ($l === null) {
            return $l;
        }

        return $this->read($l);
    }

    /**
     * Reads string until NULL character
     *
     * @return string
     * @throws \LogicException
     */
    public function readStringNull()
    {
        $pos = \strpos($this->buffer, "\0", $this->bufferPos);
        if ($pos === false) {
            throw new \LogicException('Missing NULL character');
        }

        $ret = $this->read($pos - $this->bufferPos);
        ++$this->bufferPos;

        return $ret;
    }

    /**
     * @param int $int
     * @return string
     */
    public function buildInt1($int)
    {
        return \chr($int);
    }

    /**
     * @param int $int
     * @return string
     */
    public function buildInt2($int)
    {
        return \pack('v', $int);
    }

    /**
     * @param int $int
     * @return string
     */
    public function buildInt3($int)
    {
        return \substr(\pack('V', $int), 0, 3);
    }

    /**
     * @param int $int
     * @return string
     * @codeCoverageIgnore
     */
    public function buildInt8($int)
    {
        // PHP < 5.6.3 does not support packing 64 bit ints, so use manual bit shifting
        if (\PHP_VERSION_ID < 50603) {
            return \pack('VV', $int, $int >> 32);
        }
        return \pack('P', $int);
    }

    /**
     * Builds length-encoded binary string
     *
     * @param string|null $s
     * @return string Resulting binary string
     */
    public function buildStringLen($s)
    {
        if ($s === NULL) {
            // \xFB (251)
            return "\xFB";
        }

        $l = \strlen($s);

        if ($l <= 250) {
            // this is the only path that is currently used in fact.
            return $this->buildInt1($l) . $s;
        }

        if ($l <= 0xFFFF) {
            // max 2^16: \xFC (252)
            return "\xFC" . $this->buildInt2($l) . $s;
        }

        if ($l <= 0xFFFFFF) {
            // max 2^24: \xFD (253)
            return "\xFD" . $this->buildInt3($l) . $s;
        }

        // max 2^64: \xFE (254)
        return "\xFE" . $this->buildInt8($l) . $s;
    }
}
