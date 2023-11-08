<?php

namespace React\MySQL\Io;

use React\MySQL\Commands\AuthenticateCommand;
use React\MySQL\Commands\QueryCommand;
use React\MySQL\Commands\QuitCommand;
use React\MySQL\Exception as MysqlException;
use React\Stream\DuplexStreamInterface;

/**
 * @internal
 */
class Parser
{
    const PHASE_GOT_INIT   = 1;
    const PHASE_AUTH_SENT  = 2;
    const PHASE_AUTH_ERR   = 3;
    const PHASE_HANDSHAKED = 4;

    const RS_STATE_HEADER = 0;
    const RS_STATE_FIELD  = 1;
    const RS_STATE_ROW    = 2;

    const STATE_STANDBY = 0;
    const STATE_BODY    = 1;

    /**
     * The packet header always consists of 4 bytes, 3 bytes packet length + 1 byte sequence number
     *
     * @var integer
     */
    const PACKET_SIZE_HEADER = 4;

    /**
     * Keeps a reference to the command that is currently being processed.
     *
     * The MySQL protocol is inherently sequential, the pending commands will be
     * stored in an `Executor` queue.
     *
     * The MySQL protocol communication starts with the server sending a
     * handshake message, so the current command will be `null` until it's our
     * turn.
     *
     * Similarly, when one command is finished, it will continue processing the
     * next command from the `Executor` queue. If no command is outstanding,
     * this will be reset to the `null` state.
     *
     * @var \React\MySQL\Commands\AbstractCommand|null
     */
    protected $currCommand;

    protected $debug = false;

    protected $state = 0;

    protected $phase = 0;

    public $seq = 0;

    public $warningCount;
    public $message;

    protected $serverVersion;
    protected $threadId;
    protected $scramble;

    protected $serverCaps;
    protected $serverLang;
    protected $serverStatus;

    protected $rsState = 0;

    /**
     * Packet size expected in number of bytes
     *
     * Depending on `self::$state`, the Parser excepts either a packet header
     * (always 4 bytes) or the packet contents (n bytes determined by prior
     * packet header).
     *
     * @var int
     * @see self::$state
     * @see self::PACKET_SIZE_HEADER
     */
    private $pctSize = self::PACKET_SIZE_HEADER;

    protected $resultFields = [];

    protected $insertId;
    protected $affectedRows;

    public $protocolVersion = 0;

    private $buffer;

    protected $connectOptions;

    /**
     * @var \React\Stream\DuplexStreamInterface
     */
    protected $stream;
    /**
     * @var Executor
     */
    protected $executor;

    public function __construct(DuplexStreamInterface $stream, Executor $executor)
    {
        $this->stream   = $stream;
        $this->executor = $executor;

        $this->buffer   = new Buffer();
        $executor->on('new', function () {
            $this->nextRequest();
        });
    }

    public function start()
    {
        $this->stream->on('data', [$this, 'handleData']);
        $this->stream->on('close', [$this, 'onClose']);
    }

    public function debug($message)
    {
        if ($this->debug) {
            $bt = \debug_backtrace();
            $caller = \array_shift($bt);
            printf("[DEBUG] <%s:%d> %s\n", $caller['class'], $caller['line'], $message);
        }
    }

    /** @var string $data */
    public function handleData($data)
    {
        $this->buffer->append($data);

        if ($this->debug) {
            $this->debug('Received ' . strlen($data) . ' byte(s), buffer now has ' . ($len = $this->buffer->length()) . ' byte(s): ' . wordwrap(bin2hex($b = $this->buffer->read($len)), 2, ' ', true)); $this->buffer->append($b); // @codeCoverageIgnore
        }

        while ($this->buffer->length() >= $this->pctSize) {
            if ($this->state === self::STATE_STANDBY) {
                $this->pctSize = $this->buffer->readInt3();
                //printf("packet size:%d\n", $this->pctSize);
                $this->state = self::STATE_BODY;
                $this->seq = $this->buffer->readInt1() + 1;
            }

            $len = $this->buffer->length();
            if ($len < $this->pctSize) {
                $this->debug('Waiting for complete packet with ' . $len . '/' . $this->pctSize . ' bytes');

                return;
            }

            $packet = $this->buffer->readBuffer($this->pctSize);
            $this->state = self::STATE_STANDBY;
            $this->pctSize = self::PACKET_SIZE_HEADER;

            try {
                $this->parsePacket($packet);
            } catch (\UnderflowException $e) {
                $this->onError(new \UnexpectedValueException('Unexpected protocol error, received malformed packet: ' . $e->getMessage(), 0, $e));
                $this->stream->close();
                return;
            }

            if ($packet->length() !== 0) {
                $this->onError(new \UnexpectedValueException('Unexpected protocol error, received malformed packet with ' . $packet->length() . ' unknown byte(s)'));
                $this->stream->close();
                return;
            }
        }
    }

    /** @return void */
    private function parsePacket(Buffer $packet)
    {
        if ($this->debug) {
            $this->debug('Parse packet#' . $this->seq . ' with ' . ($len = $packet->length()) . ' bytes: ' . wordwrap(bin2hex($b = $packet->read($len)), 2, ' ', true)); $packet->append($b); // @codeCoverageIgnore
        }

        if ($this->phase === 0) {
            $response = $packet->readInt1();
            if ($response === 0xFF) {
                // error packet before handshake means we did not exchange capabilities and error does not include SQL state
                $this->phase   = self::PHASE_AUTH_ERR;

                $code = $packet->readInt2();
                $exception = new MysqlException($packet->read($packet->length()), $code);
                $this->debug(sprintf("Error Packet:%d %s\n", $code, $exception->getMessage()));

                // error during init phase also means we're not currently executing any command
                // simply reject the first outstanding command in the queue (AuthenticateCommand)
                $this->currCommand = $this->executor->dequeue();
                $this->onError($exception);
                return;
            }

            $this->phase = self::PHASE_GOT_INIT;
            $this->protocolVersion = $response;
            $this->debug(sprintf("Protocol Version: %d", $this->protocolVersion));

            $options = &$this->connectOptions;
            $options['serverVersion'] = $packet->readStringNull();
            $options['threadId']      = $packet->readInt4();
            $this->scramble           = $packet->read(8); // 1st part
            $packet->skip(1); // filler
            $options['ServerCaps']    = $packet->readInt2(); // 1st part
            $options['serverLang']    = $packet->readInt1();
            $options['serverStatus']  = $packet->readInt2();
            $options['ServerCaps']   += $packet->readInt2() << 16; // 2nd part
            $packet->skip(11); // plugin length, 6 + 4 filler
            $this->scramble          .= $packet->read(12); // 2nd part
            $packet->skip(1);

            if ($this->connectOptions['ServerCaps'] & Constants::CLIENT_PLUGIN_AUTH) {
                $packet->readStringNull(); // skip authentication plugin name
            }

            // init completed, continue with sending AuthenticateCommand
            $this->nextRequest(true);
        } else {
            $fieldCount = $packet->readInt1();

            if ($fieldCount === 0xFF) {
                // error packet
                $code = $packet->readInt2();
                $packet->skip(6); // skip SQL state
                $exception = new MysqlException($packet->read($packet->length()), $code);
                $this->debug(sprintf("Error Packet:%d %s\n", $code, $exception->getMessage()));

                $this->onError($exception);
                $this->nextRequest();
            } elseif ($fieldCount === 0x00 && $this->rsState !== self::RS_STATE_ROW) {
                // Empty OK Packet terminates a query without a result set (UPDATE, INSERT etc.)
                $this->debug('Ok Packet');

                if ($this->phase === self::PHASE_AUTH_SENT) {
                    $this->phase = self::PHASE_HANDSHAKED;
                }

                $this->affectedRows = $packet->readIntLen();
                $this->insertId     = $packet->readIntLen();
                $this->serverStatus = $packet->readInt2();
                $this->warningCount = $packet->readInt2();

                $this->message      = $packet->read($packet->length());

                $this->debug(sprintf("AffectedRows: %d, InsertId: %d, WarningCount:%d", $this->affectedRows, $this->insertId, $this->warningCount));
                $this->onSuccess();
                $this->nextRequest();
            } elseif ($fieldCount === 0xFE) {
                // EOF Packet
                $packet->skip(4); // warn, status
                if ($this->rsState === self::RS_STATE_ROW) {
                    // finalize this result set (all rows completed)
                    $this->debug('Result set done');

                    $this->onResultDone();
                    $this->nextRequest();
                } else {
                    // move to next part of result set (header->field->row)
                    $this->debug('Result set next part');
                    ++$this->rsState;
                }
            } else {
                // Data packet
                $packet->prepend($packet->buildInt1($fieldCount));

                if ($this->rsState === self::RS_STATE_HEADER) {
                    $columns = $packet->readIntLen(); // extra
                    $this->debug('Result set with ' . $columns . ' column(s)');
                    $this->rsState = self::RS_STATE_FIELD;
                } elseif ($this->rsState === self::RS_STATE_FIELD) {
                    $field = [
                        'catalog'   => $packet->readStringLen(),
                        'db'        => $packet->readStringLen(),
                        'table'     => $packet->readStringLen(),
                        'org_table' => $packet->readStringLen(),
                        'name'      => $packet->readStringLen(),
                        'org_name'  => $packet->readStringLen()
                    ];

                    $packet->skip(1); // 0xC0
                    $field['charset']     = $packet->readInt2();
                    $field['length']      = $packet->readInt4();
                    $field['type']        = $packet->readInt1();
                    $field['flags']       = $packet->readInt2();
                    $field['decimals']    = $packet->readInt1();
                    $packet->skip(2); // unused

                    if ($this->debug) {
                        $this->debug('Result set column: ' . json_encode($field, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE)); // @codeCoverageIgnore
                    }
                    $this->resultFields[] = $field;
                } elseif ($this->rsState === self::RS_STATE_ROW) {
                    $row = [];
                    foreach ($this->resultFields as $field) {
                        $row[$field['name']] = $packet->readStringLen();
                    }

                    if ($this->debug) {
                        $this->debug('Result set row: ' . json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE)); // @codeCoverageIgnore
                    }
                    $this->onResultRow($row);
                }
            }
        }
    }

    private function onResultRow($row)
    {
        // $this->debug('row data: ' . json_encode($row));
        $command = $this->currCommand;
        $command->emit('result', [$row]);
    }

    private function onError(\Exception $error)
    {
        $this->rsState      = self::RS_STATE_HEADER;
        $this->resultFields = [];

        // reject current command with error if we're currently executing any commands
        // ignore unsolicited server error in case we're not executing any commands (connection will be dropped)
        if ($this->currCommand !== null) {
            $command = $this->currCommand;
            $this->currCommand = null;

            $command->emit('error', [$error]);
        }
    }

    protected function onResultDone()
    {
        $command = $this->currCommand;
        $this->currCommand = null;

        assert($command instanceof QueryCommand);
        $command->fields = $this->resultFields;
        $command->emit('end');

        $this->rsState      = self::RS_STATE_HEADER;
        $this->resultFields = [];
    }

    protected function onSuccess()
    {
        $command = $this->currCommand;
        $this->currCommand = null;

        if ($command instanceof QueryCommand) {
            $command->affectedRows = $this->affectedRows;
            $command->insertId     = $this->insertId;
            $command->warningCount = $this->warningCount;
        }
        $command->emit('success');
    }

    public function onClose()
    {
        if ($this->currCommand !== null) {
            $command = $this->currCommand;
            $this->currCommand = null;

            if ($command instanceof QuitCommand) {
                $command->emit('success');
            } else {
                $command->emit('error', [new \RuntimeException(
                    'Connection closing (ECONNABORTED)',
                    \defined('SOCKET_ECONNABORTED') ? \SOCKET_ECONNABORTED : 103
                )]);
            }
        }
    }

    public function sendPacket($packet)
    {
        return $this->stream->write($this->buffer->buildInt3(\strlen($packet)) . $this->buffer->buildInt1($this->seq++) . $packet);
    }

    protected function nextRequest($isHandshake = false)
    {
        if (!$isHandshake && $this->phase != self::PHASE_HANDSHAKED) {
            return false;
        }

        if ($this->currCommand === null && !$this->executor->isIdle()) {
            $command = $this->executor->dequeue();
            $this->currCommand = $command;

            if ($command instanceof AuthenticateCommand) {
                $this->phase = self::PHASE_AUTH_SENT;
                $this->sendPacket($command->authenticatePacket($this->scramble, $this->buffer));
            } else {
                $this->seq = 0;
                $this->sendPacket($this->buffer->buildInt1($command->getId()) . $command->getSql());
            }
        }

        return true;
    }
}
