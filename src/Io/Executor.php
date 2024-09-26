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
        /**
         * MemoryLeak: Make sure removeAllListeners is called on commands,
         * otherwise they might stay in memory for ever.
         */
        $command->on(
            'error',
            function () use ($command) {
                $command->removeAllListeners();
            }
        );
        $command->on(
            'success',
            function () use ($command) {
                $command->removeAllListeners();
            }
        );
        $command->on(
            'end',
            function () use ($command) {
                $command->removeAllListeners();
            }
        );
        $this->queue->enqueue($command);
        $this->emit('new');

        return $command;
    }

    public function dequeue()
    {
        return $this->queue->dequeue();
    }
}
