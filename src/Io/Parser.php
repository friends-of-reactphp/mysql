<?php

namespace React\MySQL\Io;

use React\MySQL\Commands\AuthenticateCommand;
use React\MySQL\Commands\QueryCommand;
use React\MySQL\Commands\QuitCommand;
use React\MySQL\Exception;
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
    protected $pctSize = 0;
    protected $resultFields = [];

    protected $insertId;
    protected $affectedRows;

    public $protocalVersion = 0;

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
        $this->stream->on('data', array($this, 'parse'));
        $this->stream->on('close', array($this, 'onClose'));
    }

    public function debug($message)
    {
        if ($this->debug) {
            $bt = \debug_backtrace();
            $caller = \array_shift($bt);
            printf("[DEBUG] <%s:%d> %s\n", $caller['class'], $caller['line'], $message);
        }
    }

    public function parse($data)
    {
        $this->buffer->append($data);
packet:
        if ($this->state === self::STATE_STANDBY) {
            if ($this->buffer->length() < 4) {
                return;
            }

            $this->pctSize = $this->buffer->readInt3();
            //printf("packet size:%d\n", $this->pctSize);
            $this->state = self::STATE_BODY;
            $this->seq = $this->buffer->readInt1() + 1;
        }

        $len = $this->buffer->length();
        if ($len < $this->pctSize) {
            $this->debug('Buffer not enouth, return');

            return;
        }
        $this->state = self::STATE_STANDBY;
        //$this->stream->bufferSize = 4;
        if ($this->phase === 0) {
            $response = $this->buffer->readInt1();
            if ($response === 0xFF) {
                // error packet before handshake means we did not exchange capabilities and error does not include SQL state
                $this->phase   = self::PHASE_AUTH_ERR;

                $code = $this->buffer->readInt2();
                $exception = new Exception($this->buffer->read($this->pctSize - $len + $this->buffer->length()), $code);
                $this->debug(sprintf("Error Packet:%d %s\n", $code, $exception->getMessage()));

                // error during init phase also means we're not currently executing any command
                // simply reject the first outstanding command in the queue (AuthenticateCommand)
                $this->currCommand = $this->executor->dequeue();
                $this->onError($exception);
                return;
            }

            $this->phase = self::PHASE_GOT_INIT;
            $this->protocalVersion = $response;
            $this->debug(sprintf("Protocal Version: %d", $this->protocalVersion));

            $options = &$this->connectOptions;
            $options['serverVersion'] = $this->buffer->readStringNull();
            $options['threadId']      = $this->buffer->readInt4();
            $this->scramble           = $this->buffer->read(8); // 1st part
            $this->buffer->skip(1); // filler
            $options['ServerCaps']    = $this->buffer->readInt2(); // 1st part
            $options['serverLang']    = $this->buffer->readInt1();
            $options['serverStatus']  = $this->buffer->readInt2();
            $options['ServerCaps']   += $this->buffer->readInt2() << 16; // 2nd part
            $this->buffer->skip(11); // plugin length, 6 + 4 filler
            $this->scramble          .= $this->buffer->read(12); // 2nd part
            $this->buffer->skip(1);

            if ($this->connectOptions['ServerCaps'] & Constants::CLIENT_PLUGIN_AUTH) {
                $this->buffer->readStringNull(); // skip authentication plugin name
            }

            // init completed, continue with sending AuthenticateCommand
            $this->nextRequest(true);
        } else {
            $fieldCount = $this->buffer->readInt1();

            if ($fieldCount === 0xFF) {
                // error packet
                $code = $this->buffer->readInt2();
                $this->buffer->skip(6); // skip SQL state
                $exception = new Exception($this->buffer->read($this->pctSize - $len + $this->buffer->length()), $code);
                $this->debug(sprintf("Error Packet:%d %s\n", $code, $exception->getMessage()));

                $this->onError($exception);
                $this->nextRequest();
            } elseif ($fieldCount === 0x00 && $this->rsState !== self::RS_STATE_ROW) {
                // Empty OK Packet terminates a query without a result set (UPDATE, INSERT etc.)
                $this->debug('Ok Packet');

                if ($this->phase === self::PHASE_AUTH_SENT) {
                    $this->phase = self::PHASE_HANDSHAKED;
                }

                $this->affectedRows = $this->buffer->readIntLen();
                $this->insertId     = $this->buffer->readIntLen();
                $this->serverStatus = $this->buffer->readInt2();
                $this->warningCount    = $this->buffer->readInt2();

                $this->message      = $this->buffer->read($this->pctSize - $len + $this->buffer->length());

                $this->debug(sprintf("AffectedRows: %d, InsertId: %d, WarningCount:%d", $this->affectedRows, $this->insertId, $this->warningCount));
                $this->onSuccess();
                $this->nextRequest();
            } elseif ($fieldCount === 0xFE) {
                // EOF Packet
                $this->buffer->skip(4); // warn, status
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
            } elseif ($fieldCount === 0x00 && $this->pctSize === 1) {
                // Empty data packet during result set => row with only empty strings
                $this->debug('Result set empty row data');

                $row = array();
                foreach ($this->resultFields as $field) {
                    $row[$field['name']] = '';
                }
                $this->onResultRow($row);
            } else {
                // Data packet
                $this->buffer->prepend($this->buffer->buildInt1($fieldCount));

                if ($this->rsState === self::RS_STATE_HEADER) {
                    $this->debug('Result set header packet');
                    $this->buffer->readIntLen(); // extra
                    $this->rsState = self::RS_STATE_FIELD;
                } elseif ($this->rsState === self::RS_STATE_FIELD) {
                    $this->debug('Result set field packet');
                    $field = [
                        'catalog'   => $this->buffer->readStringLen(),
                        'db'        => $this->buffer->readStringLen(),
                        'table'     => $this->buffer->readStringLen(),
                        'org_table' => $this->buffer->readStringLen(),
                        'name'      => $this->buffer->readStringLen(),
                        'org_name'  => $this->buffer->readStringLen()
                    ];

                    $this->buffer->skip(1); // 0xC0
                    $field['charset']     = $this->buffer->readInt2();
                    $field['length']      = $this->buffer->readInt4();
                    $field['type']        = $this->buffer->readInt1();
                    $field['flags']       = $this->buffer->readInt2();
                    $field['decimals']    = $this->buffer->readInt1();
                    $this->buffer->skip(2); // unused
                    $this->resultFields[] = $field;
                } elseif ($this->rsState === self::RS_STATE_ROW) {
                    $this->debug('Result set row data');
                    $row = [];
                    foreach ($this->resultFields as $field) {
                        $row[$field['name']] = $this->buffer->readStringLen();
                    }
                    $this->onResultRow($row);
                }
            }
        }

        $this->buffer->trim();
        goto packet;
    }

    private function onResultRow($row)
    {
        // $this->debug('row data: ' . json_encode($row));
        $command = $this->currCommand;
        $command->emit('result', array($row));
    }

    private function onError(Exception $error)
    {
        // reject current command with error if we're currently executing any commands
        // ignore unsolicited server error in case we're not executing any commands (connection will be dropped)
        if ($this->currCommand !== null) {
            $command = $this->currCommand;
            $this->currCommand = null;

            $command->emit('error', array($error));
        }
    }

    protected function onResultDone()
    {
        $command = $this->currCommand;
        $this->currCommand = null;

        $command->resultFields = $this->resultFields;
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
            $command->warningCount    = $this->warningCount;
            $command->message      = $this->message;
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
                $command->emit('error', array(
                    new \RuntimeException('Connection lost')
                ));
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
