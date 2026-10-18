<?php

namespace Tenma;

/** A timer from setTimeout() or setInterval(). */
final class Timer
{
    private int $op = 0;
    private bool $cancelled = false;
    /** The request the timer was set in: if the client leaves, the timer ends too. */
    private readonly ?int $request;
    /** @var array<int, mixed> AsyncLocal values from where the timer was set */
    private readonly array $context;

    /** @internal */
    public function __construct(
        private readonly \Closure $callback,
        private readonly int $milliseconds,
        private readonly bool $repeat,
    ) {
        $this->request = Internal\Current::$request;
        $this->context = Internal\context();
        if ($this->request !== null) {
            Internal\own($this->request, $this);
        }
    }

    private function forget(): void
    {
        if ($this->request !== null) {
            Internal\disown($this->request, $this);
        }
    }

    /** @internal */
    public function arm(): void
    {
        if ($this->cancelled) {
            return;
        }
        $this->op = \tenma_op_sleep($this->milliseconds);
        if ($this->op !== 0) {
            Internal\Waiting::$timers[$this->op] = $this;
        }
    }

    /** @internal The wait is over: callback in its own Fiber; for setInterval, re-arm right away. */
    public function fire(): void
    {
        $this->op = 0;
        if ($this->cancelled) {
            return;
        }
        if ($this->repeat) {
            $this->arm();
        } else {
            $this->forget();
        }
        // The callback runs as background work, as before (exit() inside it ends Tenma); only the
        // wait until then belongs to the request
        Task::start($this->callback, null, $this->context, $this->repeat ? 'Interval' : 'Timer');
    }

    /** Stops the timer; a callback already running runs to completion. */
    public function cancel(): void
    {
        $this->cancelled = true;
        $this->forget();
        if ($this->op !== 0) {
            unset(Internal\Waiting::$timers[$this->op]);
            \tenma_op_cancel($this->op);
            $this->op = 0;
        }
    }
}
