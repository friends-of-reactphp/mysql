<?php

namespace React\MySQL\Io;

use Evenement\EventEmitter;

/**
 * @internal
 */
class Executor extends EventEmitter
{
    public $queue;

    public function __construct()
    {
        $this->queue = new \SplQueue();
    }

    public function isIdle()
    {
        return $this->queue->isEmpty();
    }

    public function enqueue($command)
    {
        $this->queue->enqueue($command);
        $this->emit('new');

        return $command;
    }

    public function dequeue()
    {
        return $this->queue->dequeue();
    }
}
