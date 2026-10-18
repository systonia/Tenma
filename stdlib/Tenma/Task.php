<?php

namespace Tenma;

/**
 * Work that runs in its own Fiber while the caller carries on; await() fetches its result.
 * Created with async() or all(). Belongs to the request it was created in.
 */
final class Task
{
    public readonly ?int $request;
    private bool $done = false;
    private mixed $result = null;
    private ?\Throwable $error = null;
    private bool $observed = false;
    /** A cancellation not yet delivered: the task's next wait throws it. */
    private ?\Throwable $cancelled = null;
    private bool $cancelRequested = false;
    private bool $requestEnded = false;
    private ?\Fiber $fiber = null;
    /** @var array<int, \Closure(self): void> */
    private array $listeners = [];
    private int $nextListener = 0;
    /** @internal @var array<int, mixed> AsyncLocal values, copied from the creator at start */
    public array $context;
    /** @internal What tenma --inspect calls the task: method and URI of a request, Timer, Task. */
    public string $label = 'Task';

    /** @param array<int, mixed>|null $context null: that of the running code */
    private function __construct(?int $request, ?array $context = null)
    {
        $this->request = $request;
        $this->context = $context ?? Internal\context();
    }

    /**
     * @internal Starts $work right away; runs until the first op, then the caller continues. The
     * task belongs to $request (default: the current one) and is cancelled with it.
     */
    public static function start(callable $work, int|null|false $request = false, ?array $context = null, string $label = 'Task'): self
    {
        $task = new self($request === false ? Internal\Current::$request : $request, $context);
        $task->label = $label;
        $task->begin($work);
        return $task;
    }

    /** @internal Starts $work once the event loop is running, for work from the script's startup. */
    public static function later(callable $work): self
    {
        $task = new self(null);
        Internal\whenRunning(fn () => $task->begin($work));
        return $task;
    }

    private function begin(callable $work): void
    {
        if ($this->cancelled !== null) {
            $this->settle(null, $this->takeCancellation());
            return;
        }
        $task = $this;
        $request = $this->request;
        $this->fiber = new \Fiber(static function () use ($task, $work, $request): void {
            Internal\Current::$request = $request;
            try {
                $result = $work();
            } catch (\Throwable $error) {
                $task->settle(null, $error);
                return;
            }
            $task->settle($result, null);
        });
        Internal\Scheduler::$tasks[spl_object_id($this)] = $this;
        Internal\Scheduler::$taskOfFiber[spl_object_id($this->fiber)] = $this;
        if ($request !== null) {
            Internal\own($request, $this);
        }
        // The caller's request, not the task's: after Tenma::detach() that would be null
        $caller = Internal\Current::$request;
        $this->fiber->start();
        Internal\Current::$request = $caller;
    }

    /** @internal */
    public function settle(mixed $result, ?\Throwable $error): void
    {
        if ($this->done) {
            return;
        }
        $this->done = true;
        $this->result = $result;
        $this->error = $error;
        unset(Internal\Scheduler::$tasks[spl_object_id($this)]);
        if ($this->request !== null) {
            Internal\disown($this->request, $this);
        }
        if ($this->fiber !== null) {
            unset(Internal\Scheduler::$taskOfFiber[spl_object_id($this->fiber)]);
        }
        $listeners = $this->listeners;
        $this->listeners = [];
        foreach ($listeners as $listener) {
            $listener($this);
        }
    }

    /** @internal Calls $listener once the task is done; if it already is, right away. Returns an ID for offSettled(). */
    public function onSettled(\Closure $listener): int
    {
        if ($this->done) {
            $listener($this);
            return -1;
        }
        $this->listeners[$this->nextListener] = $listener;
        return $this->nextListener++;
    }

    /** @internal */
    public function offSettled(int $id): void
    {
        unset($this->listeners[$id]);
    }

    /** Waits until the task is done, without blocking the server; returns its result or throws its error. */
    public function await(): mixed
    {
        $this->observed = true;
        if (!$this->done) {
            $fiber = Internal\requireFiber();
            if ($fiber === $this->fiber) {
                throw new \LogicException('A task cannot wait for itself');
            }
            $token = Internal\token('task');
            $listener = $this->onSettled(fn () => Internal\wake($fiber, $token));
            try {
                Internal\suspend($token);
            } finally {
                // Interrupted (cancellation): otherwise the listener would hold the Fiber until this task ends
                $this->offSettled($listener);
            }
        }
        return $this->error !== null ? throw $this->error : $this->result;
    }

    /**
     * Cancels the task: wherever it is waiting (op, task, all()), $error is thrown (default: Cancelled);
     * finally and catch blocks run. If it is not waiting right now, its next wait throws.
     */
    public function cancel(?\Throwable $error = null): void
    {
        if ($this->done || $this->cancelRequested) {
            return;
        }
        $this->cancelRequested = true;
        $this->deliver($error ?? new Cancelled('Task cancelled'));
    }

    /**
     * @internal Its request ended (client gone, exit(), response out): delivered once more even after
     * a cancel(), which the task may have caught to retry; only one per request, so cleanup can wait.
     */
    public function endWithRequest(\Throwable $error): void
    {
        if ($this->done || $this->requestEnded) {
            return;
        }
        $this->requestEnded = $this->cancelRequested = true;
        $this->deliver($error);
    }

    private function deliver(\Throwable $error): void
    {
        if ($this->fiber !== null && $this->fiber->isTerminated()) {
            // The Fiber ended without settle(), for example through exit(): whoever waits for it must not wait forever
            $this->settle(null, $error);
            return;
        }
        // If the task is waiting right now, the cancellation is thrown there; otherwise at its next wait.
        // Afterwards it may clean up and wait again for that (for example a rollback in catch)
        if ($this->fiber === null || !Internal\interrupt($this->fiber, $error)) {
            $this->cancelled = $error;
        }
    }

    /** @internal A pending cancellation, delivered exactly once. */
    public function takeCancellation(): ?\Throwable
    {
        $cancelled = $this->cancelled;
        $this->cancelled = null;
        return $cancelled;
    }

    /** @internal */
    public function failed(): bool
    {
        return $this->error !== null;
    }

    public function isDone(): bool
    {
        return $this->done;
    }

    /** If nobody ever calls await(), the error is not silently lost. */
    public function __destruct()
    {
        if ($this->error !== null && !$this->observed && !$this->error instanceof Cancelled) {
            error_log('Error in a task that nobody awaited: ' . $this->error);
        }
    }
}
