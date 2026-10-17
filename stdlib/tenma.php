<?php
// The standard library of Tenma, embedded in the binary and loaded before the script.
// It builds on the raw functions of the C layer (tenma_listen, tenma_respond, tenma_op_*)
// and gives them a PHP API.
//
// Asynchronous without an await keyword: every request runs in its own Fiber.
// When the code starts an op (sleep, fetch), the Fiber pauses, the event loop serves other
// requests, and once the op is done, execution continues at the same spot. To the code this
// looks synchronous.

namespace Tenma\Internal {
    /** Fibers waiting for an op, by op ID, with the request they belong to; plus timers. */
    final class Waiting
    {
        /** @var array<int, \Fiber> */
        public static array $ops = [];
        /** @var array<int, \Tenma\Timer> */
        public static array $timers = [];
        /** @var array<int, \Tenma\Subscription> */
        public static array $subscriptions = [];
    }

    /** The request whose code is running right now; tasks and ops belong to it, exit() ends it. */
    final class Current
    {
        public static ?int $request = null;
        /** A classic request that still has to respond. */
        public static ?int $classic = null;
        /** The classic request ended with an exception. */
        public static bool $failed = false;
    }

    /** Is the event loop running yet? What the script schedules at startup waits until then. */
    final class Loop
    {
        public static bool $running = false;
        /** @var list<\Closure(): void> */
        public static array $pending = [];
    }

    /**
     * Who waits for what. Every sleeping Fiber waits for a token (an op, a task, an all());
     * a wake with a different token is stale and gets dropped. This lets Tenma also interrupt a
     * waiting Fiber, for example to cancel it, without it being woken again later.
     */
    final class Scheduler
    {
        /** @var array<int, array{string, int|null}> Fiber ID → [token, request] */
        public static array $waiting = [];
        /** @var list<array{\Fiber, string, mixed}|null> */
        public static array $ready = [];
        public static int $head = 0;
        public static int $tokens = 0;
        /** @var array<int, \Tenma\Task> running tasks by task ID */
        public static array $tasks = [];
        /** @var array<int, \Tenma\Task> Fiber ID → task */
        public static array $taskOfFiber = [];
        /** @var array<int, array<int, \Tenma\Task|\Tenma\Timer|\Tenma\Subscription>> Request → running tasks, timers and subscriptions, by object ID */
        public static array $ofRequest = [];
        /** @var array<int, \Throwable> Request → the error its work ended with, while some of that work still runs */
        public static array $ended = [];
        /** @var array<int, mixed> Values of Tenma\AsyncLocal outside any task (script, start of a request) */
        public static array $rootContext = [];
    }

    /** The task whose Fiber is running code right now; null in the script or in a custom Fiber. */
    function currentTask(): ?\Tenma\Task
    {
        $fiber = \Fiber::getCurrent();
        return $fiber === null ? null : Scheduler::$taskOfFiber[spl_object_id($fiber)] ?? null;
    }

    /** @return array<int, mixed> The Tenma\AsyncLocal values in effect right now: the ones a new task inherits. */
    function context(): array
    {
        return currentTask()?->context ?? Scheduler::$rootContext;
    }

    /** The running Fiber; asynchronous functions only work inside one. */
    function requireFiber(): \Fiber
    {
        return \Fiber::getCurrent() ?? throw new \LogicException('Asynchronous Tenma functions only work in a Tenma::serve() handler, in tasks and in timers');
    }

    /** A new token for suspend() and wake(). */
    function token(string $kind): string
    {
        return $kind . ':' . ++Scheduler::$tokens;
    }

    /**
     * Pauses the running Fiber until it is woken with $token; a woken error is thrown.
     * If a cancellation is pending for the Fiber's task, it is thrown here instead of waiting ($onCancel
     * then cleans up, for example an op that has already started).
     */
    function suspend(string $token, ?\Closure $onCancel = null): mixed
    {
        $fiber = requireFiber();
        $id = spl_object_id($fiber);
        $cancelled = (Scheduler::$taskOfFiber[$id] ?? null)?->takeCancellation();
        if ($cancelled !== null) {
            $onCancel && $onCancel();
            throw $cancelled;
        }
        Scheduler::$waiting[$id] = [$token, Current::$request];
        try {
            $value = \Fiber::suspend();
        } finally {
            unset(Scheduler::$waiting[$id]);
        }
        return $value instanceof \Throwable ? throw $value : $value;
    }

    /** Queues $fiber for resuming; drain() resumes it if it is still waiting for $token by then. */
    function wake(\Fiber $fiber, string $token, mixed $value = null): void
    {
        Scheduler::$ready[] = [$fiber, $token, $value];
    }

    /**
     * Resumes woken Fibers until none is left waiting. Tenma calls this after every call into PHP
     * when no Fiber is running; the state lives in Scheduler so that an exit() midway loses nothing.
     */
    function drain(): void
    {
        // Not unset(): count() would shrink and skip Fibers queued later
        while (Scheduler::$head < count(Scheduler::$ready)) {
            [$fiber, $token, $value] = Scheduler::$ready[Scheduler::$head];
            Scheduler::$ready[Scheduler::$head] = null;
            Scheduler::$head++;
            [$waitingFor, $request] = Scheduler::$waiting[spl_object_id($fiber)] ?? [null, null];
            if ($waitingFor === $token && $fiber->isSuspended()) {
                Current::$request = $request;
                $fiber->resume($value);
            }
        }
        Scheduler::$ready = [];
        Scheduler::$head = 0;
    }

    /**
     * Interrupts whatever $fiber is waiting for and wakes it with $error: an op is cancelled, waiting
     * for a task or an all() ends.
     */
    function interrupt(\Fiber $fiber, \Throwable $error): bool
    {
        $id = spl_object_id($fiber);
        [$token, $request] = Scheduler::$waiting[$id] ?? [null, null];
        if ($token === null) {
            return false;
        }
        if (str_starts_with($token, 'op:')) {
            $op = (int) substr($token, 3);
            unset(Waiting::$ops[$op]);
            \tenma_op_cancel($op);
        }
        // A fresh token: a wake already queued with the old one (for example the op's result)
        // is now stale and does not overtake the cancellation
        $fresh = token('interrupt');
        Scheduler::$waiting[$id] = [$fresh, $request];
        wake($fiber, $fresh, $error);
        return true;
    }

    /**
     * Ends everything that belongs to a request: its tasks throw $error wherever they are waiting,
     * once, even if they were cancelled before. What its remaining work starts afterwards ends right away.
     */
    function cancelRequest(int $request, \Throwable $error, ?\Tenma\Task $except = null): void
    {
        if (empty(Scheduler::$ofRequest[$request])) {
            return;
        }
        Scheduler::$ended[$request] ??= $error;
        foreach (Scheduler::$ofRequest[$request] as $entry) {
            if ($entry !== $except) {
                $entry instanceof \Tenma\Task ? $entry->endWithRequest($error) : $entry->cancel();
            }
        }
    }

    /** Registers a task, timer or subscription of a request; if the request has ended, it ends right away. */
    function own(int $request, \Tenma\Task|\Tenma\Timer|\Tenma\Subscription $entry): void
    {
        Scheduler::$ofRequest[$request][spl_object_id($entry)] = $entry;
        if (isset(Scheduler::$ended[$request])) {
            $entry instanceof \Tenma\Task ? $entry->endWithRequest(Scheduler::$ended[$request]) : $entry->cancel();
        }
    }

    function disown(int $request, \Tenma\Task|\Tenma\Timer|\Tenma\Subscription $entry): void
    {
        unset(Scheduler::$ofRequest[$request][spl_object_id($entry)]);
        if (empty(Scheduler::$ofRequest[$request])) {
            unset(Scheduler::$ofRequest[$request], Scheduler::$ended[$request]);
        }
    }

    /**
     * The response is out, or a stream or WebSocket is over: the request's tasks, timers and
     * subscriptions end with it, as they do when a client leaves before the response.
     */
    function connectionEnded(int $request): void
    {
        cancelRequest($request, new \Tenma\Cancelled('The connection ended'), currentTask());
    }

    /**
     * Called by Tenma when the client left before the response. Unlike after exit(), catch and
     * finally still run and may wait: transactions roll themselves back there. Work that has to keep
     * running is started by the handler with Tenma::detach().
     */
    function requestGone(int $rid): void
    {
        cancelRequest($rid, new \Tenma\Cancelled('The client closed the connection'));
    }

    /** Waits in the running Fiber for an op and returns its result. */
    function await(int $op): mixed
    {
        $fiber = requireFiber();
        if ($op === 0) {
            throw new \RuntimeException('Tenma cannot start asynchronous calls right now');
        }
        Waiting::$ops[$op] = $fiber;
        [$ok, $value] = suspend("op:$op", function () use ($op): void {
            unset(Waiting::$ops[$op]);
            \tenma_op_cancel($op);
        });
        // Errors arrive as [message, code]; for files the code is errno, for example
        return $ok ? $value : throw new \RuntimeException(...(array) $value);
    }

    /** Called by Tenma when an op is done: wakes the waiting Fiber or fires the timer. */
    function complete(int $op, bool $ok, mixed $value): void
    {
        $timer = Waiting::$timers[$op] ?? null;
        if ($timer !== null) {
            unset(Waiting::$timers[$op]);
            Current::$request = null;
            $timer->fire();
            return;
        }
        $subscription = Waiting::$subscriptions[$op] ?? null;
        if ($subscription !== null) {
            unset(Waiting::$subscriptions[$op]);
            Current::$request = null;
            $subscription->fire($ok, $value);
            return;
        }
        if (isset(Waiting::$ops[$op])) {
            $fiber = Waiting::$ops[$op];
            unset(Waiting::$ops[$op]);
            wake($fiber, "op:$op", [$ok, $value]);
        }
    }

    /** Runs $start once the event loop is running; if it already is, right away. */
    function whenRunning(\Closure $start): void
    {
        if (Loop::$running) {
            $start();
        } else {
            Loop::$pending[] = $start;
        }
    }

    /** Called by Tenma when the event loop is running: starts timers and tasks from the script's startup. */
    function started(): void
    {
        Loop::$running = true;
        while (Loop::$pending) {
            $start = array_shift(Loop::$pending);
            $start();
        }
    }

    /**
     * Called by Tenma after exit() or die() in a Fiber. Inside a request only the request ends:
     * it gets 500, its streams, sockets and transactions are closed, its remaining tasks are
     * cancelled (exit() skips finally blocks). Outside a request (timer, background,
     * script) Tenma exits, like PHP.
     */
    function exited(): void
    {
        $rid = Current::$request;
        if ($rid === null) {
            \tenma_exit_process();
            return;
        }
        error_log('exit() in a Tenma::serve() handler only ends the request');
        \tenma_respond($rid, [500, ['Content-Type: text/plain; charset=utf-8'], "Internal Server Error\n"]);
        \tenma_stream_end($rid, false);
        \tenma_ws_close($rid, false);
        \tenma_db_abandon($rid);
        cancelRequest($rid, new \Tenma\Cancelled('exit() ended the request'));
        Current::$request = null;
    }

    /** Seconds for timers and sleep() as milliseconds, validated: INF, NAN and negative values are errors. */
    function milliseconds(float $seconds, int $minimum = 0): int
    {
        if (!is_finite($seconds) || $seconds < 0) {
            throw new \ValueError("Invalid wait time: $seconds seconds");
        }
        // At most about 31 years: more does not fit safely into a timer
        return max($minimum, (int) round(min($seconds, 1e9) * 1000));
    }
}

namespace Tenma {
    /**
     * The task or request was cancelled, for example because a sibling in all() failed or exit()
     * ran. Deliberately not a RuntimeException: a catch for failed ops should not swallow it.
     */
    class Cancelled extends \Exception
    {
    }

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

    /** @internal Behind Tenma::async(). */
    function async(callable $work): Task
    {
        if (!Internal\Loop::$running) {
            return Task::later($work);
        }
        Internal\requireFiber();
        return Task::start($work);
    }

    /**
     * @internal Cancels the tasks and waits until they have cleaned up. Returns a cancellation of the
     * waiting task itself that came meanwhile: it is delivered only once, so the caller rethrows it.
     *
     * @param list<Task> $tasks
     */
    function cancelAndWait(array $tasks): ?\Throwable
    {
        $cancelled = null;
        foreach ($tasks as $task) {
            $task->cancel();
        }
        foreach ($tasks as $task) {
            while (!$task->isDone()) {
                try {
                    $task->await();
                } catch (\Throwable $error) {
                    // Thrown while the task still runs: not its error, the waiting task was interrupted
                    if (!$task->isDone()) {
                        $cancelled ??= $error;
                    }
                }
            }
        }
        return $cancelled;
    }

    /** @internal Behind Tenma::all(). */
    function all(callable|Task ...$tasks): array
    {
        $fiber = Internal\requireFiber();
        $started = array_map(fn ($task) => $task instanceof Task ? $task : Task::start($task), $tasks);
        $pending = count($started);
        $failed = null;
        $token = Internal\token('all');
        $woken = false;
        foreach ($started as $task) {
            $task->onSettled(function (Task $task) use (&$pending, &$failed, &$woken, $fiber, $token): void {
                $pending--;
                if ($failed === null && $task->failed()) {
                    $failed = $task;
                }
                if (!$woken && ($pending === 0 || $failed !== null)) {
                    $woken = true;
                    Internal\wake($fiber, $token);
                }
            });
        }
        if ($pending > 0 && $failed === null) {
            try {
                Internal\suspend($token);
            } catch (\Throwable $error) {
                // all() itself was cancelled: cancel the children too, and only continue once they have cleaned up
                cancelAndWait($started);
                throw $error;
            }
        }
        if ($failed !== null) {
            // Only the first error counts; the others are consequences of the cancellation
            $cancelled = cancelAndWait($started);
            if ($cancelled !== null) {
                throw $cancelled;
            }
            $failed->await();
        }
        return array_map(fn (Task $task) => $task->await(), $started);
    }

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

    /** @internal Behind Tenma::setTimeout(). */
    function setTimeout(callable $callback, float $seconds): Timer
    {
        $timer = new Timer($callback(...), Internal\milliseconds($seconds), false);
        Internal\Loop::$running && Internal\requireFiber();
        Internal\whenRunning($timer->arm(...));
        return $timer;
    }

    /** @internal Behind Tenma::setInterval(). */
    function setInterval(callable $callback, float $seconds): Timer
    {
        $timer = new Timer($callback(...), Internal\milliseconds($seconds, 1), true);
        Internal\Loop::$running && Internal\requireFiber();
        Internal\whenRunning($timer->arm(...));
        return $timer;
    }

    /** A subscription from Tenma::subscribe(). */
    final class Subscription
    {
        private readonly int $id;
        private int $op = 0;
        private bool $cancelled = false;
        /** The request the subscription was made in: if the client leaves, it ends too. */
        private readonly ?int $request;
        /** @var array<int, mixed> AsyncLocal values from where it was made */
        private readonly array $context;

        /** @internal */
        public function __construct(string $channel, private readonly \Closure $callback)
        {
            // Right away, also at script startup: what is published until the loop runs waits in the queue
            $this->id = \tenma_pubsub('subscribe', 0, $channel, '');
            $this->request = Internal\Current::$request;
            $this->context = Internal\context();
            if ($this->request !== null) {
                Internal\own($this->request, $this);
            }
        }

        /** @internal Waits for the next message. */
        public function arm(): void
        {
            if ($this->cancelled) {
                return;
            }
            $this->op = \tenma_pubsub('receive', $this->id, '', '');
            if ($this->op !== 0) {
                Internal\Waiting::$subscriptions[$this->op] = $this;
            }
        }

        /** @internal A message came: the callback runs in its own Fiber, the next wait starts right away. */
        public function fire(bool $ok, mixed $message): void
        {
            $this->op = 0;
            if ($this->cancelled || !$ok || !is_string($message)) {
                return;
            }
            $this->arm();
            $callback = $this->callback;
            Task::start(static fn () => $callback($message), null, $this->context, 'Subscription');
        }

        /** Stops the subscription; callbacks already running run to completion. */
        public function cancel(): void
        {
            if ($this->cancelled) {
                return;
            }
            $this->cancelled = true;
            if ($this->request !== null) {
                Internal\disown($this->request, $this);
            }
            if ($this->op !== 0) {
                unset(Internal\Waiting::$subscriptions[$this->op]);
                \tenma_op_cancel($this->op);
                $this->op = 0;
            }
            \tenma_pubsub('unsubscribe', $this->id, '', '');
        }
    }

    /** @internal Behind Tenma::subscribe(). */
    function subscribe(string $channel, callable $callback): Subscription
    {
        Internal\Loop::$running && Internal\requireFiber();
        $subscription = new Subscription($channel, $callback(...));
        Internal\whenRunning($subscription->arm(...));
        return $subscription;
    }

    /**
     * A value that travels along with asynchronous work, for example the
     * request ID for log lines. Every task (async(), all(), detach(), timer) gets a copy of its creator's
     * values at start; set() in the task changes only its own copy and that of its later children. A request
     * starts with the values from the script.
     *
     * @template T
     */
    final class AsyncLocal
    {
        private static int $next = 0;
        private readonly int $id;

        public function __construct()
        {
            $this->id = ++self::$next;
        }

        /** @return T|null */
        public function get(): mixed
        {
            return Internal\context()[$this->id] ?? null;
        }

        /** @param T|null $value null removes the value */
        public function set(mixed $value): void
        {
            $task = Internal\currentTask();
            if ($task !== null) {
                $context = &$task->context;
            } else {
                $context = &Internal\Scheduler::$rootContext;
            }
            if ($value === null) {
                unset($context[$this->id]);
            } else {
                $context[$this->id] = $value;
            }
        }
    }

    /** @internal Behind Tenma::detach(). */
    function detach(callable $work): Task
    {
        Internal\requireFiber();
        return Task::start($work, null);
    }

    /** @internal Behind Tenma::sleep(). */
    function sleep(float $seconds): void
    {
        Internal\requireFiber();
        Internal\await(\tenma_op_sleep(Internal\milliseconds($seconds)));
    }
}

namespace Tenma {
    /** Asynchronous file system functions: only the Fiber waits while the work runs on a background thread. */
    final class Fs
    {
        /**
         * Files and directories, without blocking the server: the work runs on background threads,
         * only the Fiber waits. Paths as with PHP's file functions (relative to chdir(), open_basedir applies).
         * Errors (missing, no permission) throw RuntimeException with op, path and reason, errno as code.
         * A running file op cannot be cancelled; it runs to completion.
         * Only in asynchronous code (handlers, tasks, timers); file_get_contents & co. block the worker.
         */
        public static function readFile(string $path): string
        {
            return self::call('read', $path);
        }

        public static function writeFile(string $path, string $data): void
        {
            self::call('write', $path, data: $data);
        }

        public static function appendFile(string $path, string $data): void
        {
            self::call('append', $path, data: $data);
        }

        public static function exists(string $path): bool
        {
            return self::call('exists', $path);
        }

        /** @return array{size: int, modified: float, isFile: bool, isDir: bool} */
        public static function stat(string $path): array
        {
            return self::call('stat', $path);
        }

        /** @return list<string> Names in the directory, sorted, without . and .. */
        public static function readDir(string $path): array
        {
            return self::call('readDir', $path);
        }

        /** Like mkdir() in PHP: without $recursive, an error if the directory already exists. */
        public static function mkdir(string $path, bool $recursive = false): void
        {
            self::call('mkdir', $path, flag: $recursive);
        }

        /** Also across file system boundaries, then like PHP rename() as copy and delete. */
        public static function rename(string $from, string $to): void
        {
            self::call('rename', $from, $to);
        }

        /** @return int bytes copied; copying a file onto itself is an error */
        public static function copy(string $from, string $to): int
        {
            return self::call('copy', $from, $to);
        }

        public static function remove(string $path): void
        {
            self::call('remove', $path);
        }

        /** An empty directory; with $recursive, also with its contents. */
        public static function removeDir(string $path, bool $recursive = false): void
        {
            self::call('removeDir', $path, flag: $recursive);
        }

        private static function call(string $op, string $path, string $second = '', bool $flag = false, string $data = ''): mixed
        {
            \Tenma\Internal\requireFiber();
            $result = \Tenma\Internal\await(\tenma_op_fs($op, $path, $second, $flag, $data));
            return match ($op) {
                'read' => $result,
                // Names as bytes, separated by null bytes: file names need not be valid UTF-8
                'readDir' => $result === '' ? [] : explode("\0", $result),
                'exists', 'stat', 'copy' => json_decode($result, true, flags: JSON_THROW_ON_ERROR),
                default => null,
            };
        }
    }
}

namespace Tenma {
    /**
     * A build step for `tenma --watch` and `tenma build`, returned in a list from tenma.dev.php
     * next to the script. Runs once at startup and again
     * whenever a file matching $inputs changes. $run is PHP code or a command: a list of program
     * and arguments (an array is always a command) that Tenma starts as a process of its own,
     * without a shell, in the directory of tenma.dev.php.
     *
     * Tenma orders the hooks by their files: a hook reading what another one writes runs after it.
     */
    final readonly class Hook
    {
        /** @var \Closure(list<string>): mixed|list<string> */
        public \Closure|array $run;

        /**
         * @param list<string> $inputs globs relative to tenma.dev.php: * and ? within a name, ** across directories
         * @param (callable(list<string> $changed): mixed)|list<string> $run PHP code gets the changed paths, empty on the first run
         * @param list<string> $outputs files $run writes; they never trigger this hook again
         * @param ?string $name for log lines, default: the program's file name or "#N" (the position in the list)
         */
        public function __construct(
            public array $inputs,
            callable|array $run,
            public array $outputs = [],
            public ?string $name = null,
        ) {
            if (is_array($run) && ($run === [] || !array_is_list($run) || array_filter($run, fn ($part) => !is_string($part)))) {
                throw new \ValueError('A command is a non-empty list of strings: the program and its arguments');
            }
            $this->run = is_array($run) ? $run : $run(...);
        }
    }
}

namespace Tenma\Lsp {
    /**
     * What a package adds to `tenma lsp`, the language server that runs next to the editor's own
     * PHP server. A package names its provider in composer.json, `"extra": {"tenma": {"lsp":
     * "Waypoint\\View\\Editor"}}`; tenma creates it with the project's directory, in a process that loads
     * the project's autoloader. Files are absolute paths, offsets and texts are bytes: `$text` is
     * the editor's content, saved or not.
     */
    interface Provider
    {
        /** The class `$this` is bound to in $file (a template, say), or null: tenma then completes `$this->`. */
        public function thisClass(string $file): ?string;

        /** @return list<array{label: string, kind?: 'method'|'property'|'class'|'value'|'keyword', detail?: string, insert?: string}> */
        public function completions(string $file, string $text, int $offset): array;

        /** @return array{file: string, line: int}|null Where the name at $offset is declared */
        public function definition(string $file, string $text, int $offset): ?array;

        /** @return list<array{line: int, message: string, severity?: 'error'|'warning'|'info'}> Lines count from 1 */
        public function diagnostics(string $file, string $text): array;
    }

    /**
     * A provider that also colours what the editor's own grammar does not know, such as template
     * syntax: tenma answers the editor's semantic tokens with these. Optional, next to Provider.
     */
    interface Tokens
    {
        /**
         * @return list<array{line: int, column: int, length: int, type: 'keyword'|'comment'|'variable'|'property'|'method'|'function'|'class'|'string'|'number'|'operator'}>
         *     Lines count from 1, column and length are bytes within the line
         */
        public function tokens(string $file, string $text): array;
    }

    /**
     * A provider that also names the imports of a file, such as a template that sees the names its
     * class file imports: tenma resolves class names there with them (completion after `Name::`,
     * hover, F12, `@var` types, the types of loop and assigned variables). The file's own `use`
     * statements win over these for the same alias, its own namespace over the one given here.
     * Optional, next to Provider.
     */
    interface Imports
    {
        /**
         * @return array<string, string> Alias => fully qualified class name, the namespace under the key ''
         */
        public function imports(string $file, string $text): array;
    }

    /**
     * A provider that also types variables other than `$this` in a file, such as the model a
     * template renders: tenma completes `$name->`, shows hover and leads F12 on its members, follows
     * chains from it and types loop and assigned variables through it. Below an assignment or a
     * loop in the file that introduces the same name, the file's own typing wins. Optional, next
     * to Provider.
     */
    interface Variables
    {
        /**
         * @return array<string, string> Variable name without `$` => fully qualified class name
         */
        public function variables(string $file, string $text): array;
    }

    /**
     * A provider that also knows the element types of a class's array members where no PHPDoc names
     * them, such as from attributes: tenma types the variable of a loop over such a member (`foreach
     * ($order->lines as $line)`, also over a method's result and along chains) with them. A `@var` or
     * `@return` PHPDoc on the member wins. tenma asks every provider for every class it needs, whatever
     * file is open, and keeps the answer until the worker restarts. Optional, next to Provider.
     */
    interface Elements
    {
        /**
         * @return array<string, string> A property by its name, a method by its name followed by `()`, =>
         *     the fully qualified class of its elements
         */
        public function elements(string $class): array;
    }
}

namespace Tenma\Internal {
    /**
     * Runs tenma.dev.php (src/dev.rs): checks the hooks it returns and orders them, then runs each
     * once and, with $watch, again whenever their inputs change. Without $watch (`tenma build`)
     * a failed hook makes tenma exit with 1.
     */
    function dev(string $file, bool $watch): void
    {
        Dev::$directory = dirname($file);
        // What the hooks publish stays when the script reloads, and the other way round
        Diagnostics::$group = 'dev';
        $list = require $file;
        try {
            Dev::load($list);
        } catch (DevError $error) {
            // A mistake in the list, not in code: the message is enough, no stack trace
            error_log('tenma: ' . $error->getMessage());
            Dev::$hooks = [];
            Dev::$failed = true;
        }
        \Tenma\async(fn () => Dev::run($watch));
    }

    final class DevError extends \RuntimeException
    {
    }

    /** Tenma::diagnostics(): who publishes, and the project's directory for `tenma lsp`. */
    final class Diagnostics
    {
        /** The script's workers ('app') or the hooks of tenma.dev.php ('dev'): --watch clears each on its reload */
        public static string $group = 'app';

        /** The nearest directory with a composer.json above $directory, or $directory itself. */
        public static function project(string $directory): string
        {
            for ($at = $directory; !is_file("$at/composer.json"); $at = dirname($at)) {
                if (dirname($at) === $at) {
                    return $directory;
                }
            }
            return $at;
        }

        /** Where the lists of a project are: one JSON file per group and source, read by `tenma lsp`. */
        public static function directory(string $project): string
        {
            return "$project/.tenma/diagnostics";
        }
    }

    /** @param list<mixed> $list */
    function diagnostics(string $source, array $list): void
    {
        if (preg_match('/^[\w.:-]+$/', $source) !== 1) {
            throw new \InvalidArgumentException("Tenma::diagnostics(): '$source' is no source name: letters, digits, . : - and _");
        }
        $problems = [];
        foreach ($list as $i => $problem) {
            if (!is_array($problem) || !is_string($problem['file'] ?? null) || !is_int($problem['line'] ?? null) || !is_string($problem['message'] ?? null)) {
                throw new \InvalidArgumentException("Tenma::diagnostics(): entry $i needs file, line and message");
            }
            $problems[] = [
                // Relative as for PHP's file functions, from the working directory: the editor needs the whole path
                'file' => str_starts_with($problem['file'], '/') ? $problem['file'] : getcwd() . '/' . $problem['file'],
                'line' => max(1, $problem['line']),
                'column' => max(1, is_int($problem['column'] ?? null) ? $problem['column'] : 1),
                'severity' => in_array($problem['severity'] ?? null, ['warning', 'info'], true) ? $problem['severity'] : 'error',
                'message' => $problem['message'],
            ];
        }
        // The setting --watch makes (shim/tenma.c): without it there is no editor to tell
        if (get_cfg_var('tenma.watch') !== '1') {
            return;
        }
        // The hooks run without a script of their own (eval): their project is the one of tenma.dev.php, which is
        // also where --watch clears their lists
        $script = $_SERVER['SCRIPT_FILENAME'] ?? null;
        $from = match (true) {
            Diagnostics::$group === 'dev' => Dev::$directory,
            is_string($script) => dirname(realpath($script) ?: $script),
            default => (string) getcwd(),
        };
        $directory = Diagnostics::directory(Diagnostics::project($from));
        if (!is_dir($directory)) {
            @mkdir($directory, 0777, true);
            // Nothing of it belongs in the repository
            @file_put_contents(dirname($directory) . '/.gitignore', "*\n");
        }
        $file = "$directory/" . Diagnostics::$group . '.' . $source . '.json';
        // ponytail: blocking file access, only under --watch and only when something reports
        $before = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];
        $known = array_map(static fn (mixed $p) => is_array($p) ? json_encode($p) : '', is_array($before) ? $before : []);
        // Every worker publishes the same: only what is new in the file goes to stderr, once
        foreach ($problems as $problem) {
            if (!in_array(json_encode($problem), $known, true)) {
                file_put_contents('php://stderr', "{$problem['file']}:{$problem['line']}:{$problem['column']}: {$problem['severity']}: {$problem['message']}\n");
            }
        }
        // Whole or not at all for the reader: written next to it, then renamed over it
        $temporary = "$file." . getmypid() . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($temporary, json_encode($problems, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)) !== false) {
            @rename($temporary, $file);
        }
    }

    final class Dev
    {
        public static string $directory;
        /** The list of hooks was invalid: nothing runs, `tenma build` fails */
        public static bool $failed = false;
        /** @var list<array{name: string, inputs: list<string>, roots: list<array{string, bool}>, outputs: list<string>, run: \Closure|list<string>, after: list<int>}> */
        public static array $hooks = [];
        /** @var list<int> Topological: a hook comes after the ones whose outputs it reads */
        public static array $order = [];
        /** @var array<string, ?string> Outputs and their hash after the hook that wrote them */
        public static array $hashes = [];
        /** @var array<int, array{float, float}> When a hook last ran, start and end, for the emergency brake */
        public static array $ran = [];
        /** @var array<int, int> Runs in a row that its own writing triggered */
        public static array $streak = [];
        /** @var array<int, true> Stopped by the emergency brake */
        public static array $stopped = [];

        public static function load(mixed $list): void
        {
            if (!is_array($list) || !array_is_list($list)) {
                throw new DevError('tenma.dev.php must return a list of Tenma\Hook, not ' . get_debug_type($list));
            }
            foreach ($list as $index => $hook) {
                if (!$hook instanceof \Tenma\Hook) {
                    throw new DevError("tenma.dev.php: entry $index is " . get_debug_type($hook) . ', not a Tenma\Hook');
                }
                $inputs = array_map(self::absolute(...), $hook->inputs);
                self::$hooks[] = [
                    'name' => $hook->name ?? (is_array($hook->run) ? basename($hook->run[0]) : '#' . ($index + 1)),
                    'inputs' => array_map(self::regex(...), $inputs),
                    'roots' => array_map(self::root(...), $inputs),
                    'outputs' => array_map(self::absolute(...), $hook->outputs),
                    'run' => $hook->run,
                    'after' => [],
                ];
            }
            self::order();
        }

        private static function absolute(string $path): string
        {
            return str_starts_with($path, '/') ? $path : self::$directory . '/' . preg_replace('#^(\./)+#', '', $path);
        }

        private static function regex(string $glob): string
        {
            $parts = preg_split('#(\*\*/|\*\*|\*|\?)#', $glob, -1, PREG_SPLIT_DELIM_CAPTURE);
            return '#^' . implode('', array_map(fn ($part) => match ($part) {
                '**/' => '(?:.*/)?',
                '**' => '.*',
                '*' => '[^/]*',
                '?' => '[^/]',
                default => preg_quote($part, '#'),
            }, $parts)) . '$#';
        }

        /** The directory to watch for a glob: up to its first wildcard; recursive if one spans directories. */
        private static function root(string $glob): array
        {
            $parts = explode('/', $glob);
            $fixed = [];
            foreach ($parts as $part) {
                if (strpbrk($part, '*?') !== false) {
                    break;
                }
                $fixed[] = $part;
            }
            if (count($fixed) === count($parts)) {
                return [dirname($glob), false];
            }
            $rest = implode('/', array_slice($parts, count($fixed)));
            return [implode('/', $fixed) ?: '/', str_contains($rest, '/') || str_contains($rest, '**')];
        }

        private static function matches(int $index, string $path): bool
        {
            foreach (self::$hooks[$index]['inputs'] as $regex) {
                if (preg_match($regex, $path)) {
                    return true;
                }
            }
            return false;
        }

        /** Orders the hooks by their files and rejects cycles, before anything runs. */
        private static function order(): void
        {
            $count = count(self::$hooks);
            $needs = array_fill(0, $count, 0);
            $before = [];
            foreach (self::$hooks as $from => $hook) {
                foreach (array_keys(self::$hooks) as $to) {
                    if ($from !== $to && array_filter($hook['outputs'], fn ($output) => self::matches($to, $output))) {
                        self::$hooks[$from]['after'][] = $to;
                        $before[$to][] = $from;
                        $needs[$to]++;
                    }
                }
            }
            // Kahn's algorithm; among hooks that are ready, the list order decides
            $ready = array_keys(array_filter($needs, fn ($count) => $count === 0));
            while ($ready) {
                sort($ready);
                $index = array_shift($ready);
                self::$order[] = $index;
                foreach (self::$hooks[$index]['after'] as $next) {
                    if (--$needs[$next] === 0) {
                        $ready[] = $next;
                    }
                }
            }
            if (count(self::$order) === $count) {
                return;
            }
            // Every hook left waits for another one left: going backwards ends in a cycle
            $left = array_keys(array_filter($needs));
            $path = [];
            $index = $left[0];
            while (!in_array($index, $path, true)) {
                $path[] = $index;
                $index = current(array_filter($before[$index], fn ($from) => $needs[$from] > 0));
            }
            $cycle = array_reverse(array_slice($path, array_search($index, $path, true)));
            $names = array_map(fn ($index) => self::$hooks[$index]['name'], [...$cycle, $cycle[0]]);
            throw new DevError('hooks ' . implode(' → ', $names) . ' form a cycle (each reads what the other writes)');
        }

        public static function run(bool $watch): void
        {
            if ($watch && !self::$failed) {
                // Before the first run, so that changes during it are not lost; what the run writes
                // itself is no change (hashes)
                $roots = array_values(array_unique(array_merge(...array_column(self::$hooks, 'roots')), SORT_REGULAR));
                await(\tenma_op_dev('watch', json_encode(['roots' => $roots, 'directory' => self::$directory], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)));
            }
            $ok = !self::$failed && self::batch(array_fill_keys(self::$order, []));
            if (!$watch || self::$failed) {
                if (!$ok && !$watch) {
                    exit(1);
                }
                return;
            }
            await(\tenma_op_dev('settled', ''));
            while (true) {
                // Path, modification time, path, …
                $fields = explode("\0", await(\tenma_op_dev('next', '')));
                $changed = [];
                for ($field = 0; $field + 1 < count($fields); $field += 2) {
                    $changed[$fields[$field]] = (float) $fields[$field + 1];
                }
                $queue = [];
                foreach (self::$hooks as $index => $hook) {
                    $paths = array_filter($changed, fn ($path) => !in_array($path, $hook['outputs'], true) && self::matches($index, $path) && !self::unchanged($path), ARRAY_FILTER_USE_KEY);
                    if ($paths && self::allowed($index, $paths)) {
                        $queue[$index] = array_map('strval', array_keys($paths));
                    }
                }
                self::batch($queue);
            }
        }

        /**
         * Emergency brake for a hook that writes files it does not declare as outputs into its own
         * inputs: stopped after five runs in a row that it triggered itself, i.e. by files written
         * while it ran. A change by someone else wakes it.
         *
         * @param array<string, float> $paths changed paths and their modification times
         */
        private static function allowed(int $index, array $paths): bool
        {
            [$start, $end] = self::$ran[$index] ?? [INF, -INF];
            $own = !array_filter($paths, fn ($modified) => $modified < $start - 0.05 || $modified > $end + 0.05);
            if (isset(self::$stopped[$index])) {
                if ($own) {
                    return false;
                }
                unset(self::$stopped[$index]);
                self::$streak[$index] = 0;
                return true;
            }
            self::$streak[$index] = $own ? (self::$streak[$index] ?? 0) + 1 : 0;
            if (self::$streak[$index] < 5) {
                return true;
            }
            self::$stopped[$index] = true;
            error_log('tenma: hook ' . self::$hooks[$index]['name'] . ' keeps triggering itself, stopped (declare its outputs)');
            return false;
        }

        /** An output that still has the content its hook wrote. */
        private static function unchanged(string $path): bool
        {
            return array_key_exists($path, self::$hashes) && self::$hashes[$path] === self::hash($path);
        }

        private static function hash(string $path): ?string
        {
            return is_file($path) ? (hash_file('xxh128', $path) ?: null) : null;
        }

        /**
         * Runs the queued hooks in order. A hook whose outputs really changed queues the hooks
         * reading them; a failed one skips them. False if a hook failed.
         *
         * @param array<int, list<string>> $queue hook => changed paths
         */
        private static function batch(array $queue): bool
        {
            $ok = true;
            $skipped = [];
            foreach (self::$order as $index) {
                if (!isset($queue[$index])) {
                    continue;
                }
                $hook = self::$hooks[$index];
                if (isset($skipped[$index])) {
                    $skipped += array_fill_keys($hook['after'], true);
                    continue;
                }
                $before = array_map(self::hash(...), $hook['outputs']);
                $start = microtime(true);
                $done = self::once($hook, $queue[$index]);
                self::$ran[$index] = [$start, microtime(true)];
                if (!$done) {
                    $ok = false;
                    $skipped += array_fill_keys($hook['after'], true);
                    continue;
                }
                foreach ($hook['outputs'] as $position => $output) {
                    self::$hashes[$output] = self::hash($output);
                    if (self::$hashes[$output] === $before[$position]) {
                        continue;
                    }
                    foreach ($hook['after'] as $next) {
                        if (self::matches($next, $output)) {
                            $queue[$next] = [...$queue[$next] ?? [], $output];
                        }
                    }
                }
            }
            return $ok;
        }

        private static function once(array $hook, array $changed): bool
        {
            try {
                if ($hook['run'] instanceof \Closure) {
                    ($hook['run'])($changed);
                    return true;
                }
                $command = ['name' => $hook['name'], 'command' => $hook['run'], 'directory' => self::$directory];
                $status = (int) await(\tenma_op_dev('run', json_encode($command, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)));
                if ($status !== 0) {
                    throw new \RuntimeException("exited with status $status");
                }
                return true;
            } catch (\Throwable $error) {
                error_log("tenma: hook {$hook['name']} failed: " . ($hook['run'] instanceof \Closure ? $error : $error->getMessage()));
                return false;
            }
        }
    }
}

namespace Tenma\Db {
    /**
     * Postgres, asynchronous: while a query runs, only this request's Fiber waits.
     * Placeholders $1, $2, … as in Postgres; parameters are sent as text, the server determines the type.
     * Only in asynchronous code (handlers, tasks, timers); at script startup, wrap queries in Tenma::async().
     */
    final class Postgres
    {
        /** The last transaction ID of this interpreter. */
        private static int $transactions = 0;

        private function __construct(private readonly string $target) {}

        /** @param string $url postgres://user:password@host:5432/database */
        public static function connect(string $url): self
        {
            return new self($url);
        }

        /**
         * Rows as a list of associative arrays, values with Postgres' JSON types.
         *
         * @param list<mixed> $params
         * @return list<array<string, mixed>>
         */
        public function query(string $sql, array $params = []): array
        {
            return $this->call('query', $sql, $params);
        }

        /**
         * @param list<mixed> $params
         * @return array<string, mixed>|null
         */
        public function first(string $sql, array $params = []): ?array
        {
            return $this->query($sql, $params)[0] ?? null;
        }

        /**
         * Several statements separated by semicolons, without parameters, such as a migration file. They run as
         * one: a failing statement undoes the ones before it, inside transaction() as part of that transaction.
         * Therefore no COMMIT or ROLLBACK in it, and nothing that refuses to run in a transaction (CREATE INDEX
         * CONCURRENTLY, VACUUM, CREATE DATABASE). Never build it from input: without parameters, anything in it
         * is SQL.
         */
        public function script(string $sql): void
        {
            $this->call('script', $sql, []);
        }

        /**
         * For statements without a result; returns the number of affected rows.
         *
         * @param list<mixed> $params
         */
        public function execute(string $sql, array $params = []): int
        {
            return $this->call('execute', $sql, $params);
        }

        /**
         * Runs $work in a transaction: commit if $work completes, otherwise rollback.
         *
         * @template T
         * @param callable(self): T $work
         * @return T
         */
        public function transaction(callable $work): mixed
        {
            $id = ++self::$transactions;
            $transaction = new self("tx:$id");
            try {
                $this->call('begin', '', [\Tenma\Internal\Current::$request ?? 0, $id]);
            } catch (\Throwable $error) {
                // Cancelled just as BEGIN succeeded: the transaction exists, but nobody would end it
                try {
                    $transaction->call('rollback', '', []);
                } catch (\Throwable) {
                }
                throw $error;
            }
            try {
                $result = $work($transaction);
            } catch (\Throwable $error) {
                $transaction->call('rollback', '', []);
                throw $error;
            }
            $transaction->call('commit', '', []);
            return $result;
        }

        /** @param list<mixed> $params */
        private function call(string $mode, string $sql, array $params): mixed
        {
            \Tenma\Internal\requireFiber();
            $json = \Tenma\Internal\await(\tenma_op_db("pg.$mode", $this->target, $sql, json_encode(array_values($params), JSON_THROW_ON_ERROR)));
            return json_decode($json, true, flags: JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        }
    }

    /** Redis, asynchronous, one shared connection per URL and worker. */
    final class Redis
    {
        private function __construct(private readonly string $url) {}

        /** @param string $url redis://host:6379/0 */
        public static function connect(string $url = 'redis://127.0.0.1:6379'): self
        {
            return new self($url);
        }

        /**
         * Commands that would change the connection for every fiber of the worker (MULTI, SELECT,
         * SUBSCRIBE) or hold it for all of them (BLPOP and the other blocking ones).
         */
        private const SHARED_CONNECTION = [
            'MULTI', 'EXEC', 'DISCARD', 'WATCH', 'UNWATCH', 'SELECT', 'SWAPDB', 'AUTH', 'HELLO', 'RESET', 'QUIT', 'MONITOR',
            'SUBSCRIBE', 'PSUBSCRIBE', 'SSUBSCRIBE', 'UNSUBSCRIBE', 'PUNSUBSCRIBE', 'SUNSUBSCRIBE', 'WAIT', 'WAITAOF',
            'BLPOP', 'BRPOP', 'BLMOVE', 'BRPOPLPUSH', 'BLMPOP', 'BZPOPMIN', 'BZPOPMAX', 'BZMPOP',
        ];

        /** Any Redis command: $redis->command('INCR', 'orders:today'). */
        public function command(string $name, string|int|float ...$args): mixed
        {
            $upper = strtoupper($name);
            if (in_array($upper, self::SHARED_CONNECTION, true)
                || ($upper === 'CLIENT' && strtoupper((string) ($args[0] ?? '')) === 'REPLY')
                || (in_array($upper, ['XREAD', 'XREADGROUP'], true) && in_array('BLOCK', array_map(fn ($arg) => strtoupper((string) $arg), $args), true))) {
                throw new \LogicException("Redis $upper does not work on the connection all fibers of the worker share: "
                    . 'EVAL a script for atomic steps, the database in the URL (redis://host/2), Tenma::subscribe() for messages, polling instead of blocking');
            }
            \Tenma\Internal\requireFiber();
            // Length and bytes: values need not be UTF-8 (serialized or compressed data)
            $packed = '';
            foreach ([$name, ...$args] as $arg) {
                // (string) would round a float to 14 digits
                $arg = is_float($arg) ? json_encode($arg, JSON_THROW_ON_ERROR) : (string) $arg;
                $packed .= strlen($arg) . ':' . $arg;
            }
            return unserialize(\Tenma\Internal\await(\tenma_op_db('redis', $this->url, '', $packed)), ['allowed_classes' => false]);
        }

        public function get(string $key): ?string
        {
            return $this->command('GET', $key);
        }

        public function set(string $key, string $value, ?int $seconds = null): void
        {
            $seconds === null ? $this->command('SET', $key, $value) : $this->command('SET', $key, $value, 'EX', $seconds);
        }
    }
}

namespace Tenma\Http {

/**
 * An HTTP request in fiber mode, the argument of the handler of Tenma::serve(). Query, headers and body
 * are read in full before the handler runs; form(), files() and cookies() parse them on demand.
 */
final class Request
{
    public readonly string $method;
    public readonly string $uri;
    public readonly string $path;
    /** @var array<string, string|array<mixed>> */
    public readonly array $query;
    /** @var array<string, string> names in lowercase */
    public readonly array $headers;
    public readonly string $body;
    /** IP address and port of the client, or of the proxy in front of it. */
    public readonly string $remoteAddress;
    public readonly int $remotePort;
    /** Address and port the connection arrived on. */
    public readonly string $serverAddress;
    public readonly int $serverPort;

    /** @param array{method: string, uri: string, headers: array<string, string>, body: string, remote_addr: string, remote_port: int, server_addr: string, server_port: int} $raw */
    public function __construct(array $raw)
    {
        $this->method = $raw['method'];
        $this->uri = $raw['uri'];
        // parse_url() would take "//host/admin" for a host and the path /admin
        [$path, $queryString] = str_starts_with($raw['uri'], '/')
            ? explode('?', $raw['uri'], 2) + [1 => '']
            : [parse_url($raw['uri'], PHP_URL_PATH) ?: '/', parse_url($raw['uri'], PHP_URL_QUERY) ?? ''];
        $this->path = $path;
        parse_str($queryString, $query);
        $this->query = $query;
        $this->headers = $raw['headers'];
        $this->body = $raw['body'];
        $this->remoteAddress = $raw['remote_addr'];
        $this->remotePort = $raw['remote_port'];
        $this->serverAddress = $raw['server_addr'];
        $this->serverPort = $raw['server_port'];
    }

    /**
     * The host from the Host header, without port; otherwise the address the connection arrived on. The
     * client controls the Host header: for links in emails or for redirects, only use it checked against
     * a list of allowed hosts.
     */
    public function host(): string
    {
        $host = $this->header('host') ?? '';
        return $host === '' ? $this->serverAddress : (string) preg_replace('/:\d+$/', '', $host);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** The body as JSON; throws on invalid JSON. */
    public function json(): mixed
    {
        return json_decode($this->body, true, flags: JSON_THROW_ON_ERROR);
    }

    /** @var array<string, mixed>|null */
    private ?array $cookies = null;
    /** @var array{array<string, mixed>, array<string, mixed>}|null */
    private ?array $form = null;

    /**
     * The cookies like $_COOKIE under FPM, via PHP's own parser: the first of several with the same name
     * wins, a[b] becomes an array, dots and spaces in names become underscores.
     *
     * @return array<string, mixed>
     */
    public function cookies(): array
    {
        return $this->cookies ??= \tenma_parse_cookies($this->header('cookie') ?? '', false);
    }

    public function cookie(string $name): ?string
    {
        $value = $this->cookies()[$name] ?? null;
        return is_string($value) ? $value : null;
    }

    /**
     * Form fields from application/x-www-form-urlencoded or multipart/form-data, with the same
     * semantics as $_POST (a[] appends, a[b] nests, duplicates overwrite).
     *
     * @return array<string, mixed>
     */
    public function form(): array
    {
        return $this->parseForm()[0];
    }

    /**
     * Uploaded files from multipart/form-data, by field name; names as with form() (name[] becomes
     * a list, name[a] nests).
     *
     * @return array<string, mixed> UploadedFile or arrays of them
     */
    public function files(): array
    {
        return $this->parseForm()[1];
    }

    /** @return array{array<string, mixed>, array<string, mixed>} */
    private function parseForm(): array
    {
        if ($this->form !== null) {
            return $this->form;
        }
        $type = $this->header('content-type') ?? '';
        if (stripos($type, 'application/x-www-form-urlencoded') === 0) {
            parse_str($this->body, $fields);
            return $this->form = [$fields, []];
        }
        if (stripos($type, 'multipart/form-data') === 0 && preg_match('/boundary=(?:"([^"]+)"|([^;\s]+))/i', $type, $match)) {
            return $this->form = Multipart::parse($this->body, $match[1] !== '' ? $match[1] : $match[2]);
        }
        return $this->form = [[], []];
    }
}

/** An uploaded file, entirely in memory (up to --max-body). */
final class UploadedFile
{
    public function __construct(
        /** The file name from the client, without directory; never use it unchecked as a path. */
        public readonly string $name,
        /** The type the client states; not trustworthy. */
        public readonly string $type,
        private readonly string $contents,
    ) {}

    public function size(): int
    {
        return strlen($this->contents);
    }

    public function contents(): string
    {
        return $this->contents;
    }

    /** Writes the file to $path without blocking the server. */
    public function moveTo(string $path): void
    {
        \Tenma\Fs::writeFile($path, $this->contents);
    }
}

/** @internal Splits multipart/form-data into fields and files, following the rules of PHP's rfc1867 parser. */
final class Multipart
{
    /** Like max_input_vars: more parts are an attack or a bug, not forms. */
    private const MAX_PARTS = 1000;

    /** @return array{array<string, mixed>, array<string, mixed>} */
    public static function parse(string $body, string $boundary): array
    {
        // Like PHP: lines end with CRLF or just LF. With a limit, so a hostile body does not first create
        // millions of parts; the rest then ends up in the last element
        $parts = preg_split('/\r?\n--' . preg_quote($boundary, '/') . '/', "\n" . $body, self::MAX_PARTS + 2);
        // Nothing useful comes before the first boundary; nor after the last one (--)
        array_shift($parts);
        // The closing boundary (--) is no part of its own
        if (count($parts) > self::MAX_PARTS && !(count($parts) === self::MAX_PARTS + 1 && str_starts_with(end($parts), '--'))) {
            throw new \RuntimeException('Too many parts in the form');
        }
        $fields = [];
        $values = [];
        $paths = [];
        $uploads = [];
        foreach ($parts as $part) {
            if (str_starts_with($part, '--')) {
                break;
            }
            // The rest of the boundary line, then head and content, separated by an empty line
            if (!preg_match('/\A[^\n]*\n(.*?)\r?\n\r?\n/s', $part, $match)) {
                continue;
            }
            $head = $match[1];
            $content = substr($part, strlen($match[0]));
            $disposition = self::parameters(self::header($head, 'content-disposition') ?? '');
            $name = $disposition['name'] ?? null;
            if ($name === null) {
                continue;
            }
            $filename = $disposition['filename'] ?? null;
            if ($filename === null) {
                $values[] = $content;
                $fields[] = self::encodeName($name) . '=' . (count($values) - 1);
                continue;
            }
            if ($filename === '') {
                // A file field with nothing selected, as the browser sends it; PHP drops one with content too
                continue;
            }
            // Older clients send the full path, even with backslashes: only the name counts
            $uploads[] = new UploadedFile(basename(str_replace('\\', '/', $filename)), self::header($head, 'content-type') ?? 'application/octet-stream', $content);
            $paths[] = self::encodeName($name) . '=' . (count($uploads) - 1);
        }
        // Names go through PHP's own parser: a[] appends, a[b] nests, a.b becomes a_b, later ones
        // overwrite. It holds numbers first, then the values: a value never gets copied or encoded
        parse_str(implode('&', $fields), $parsed);
        parse_str(implode('&', $paths), $files);
        array_walk_recursive($parsed, function (mixed &$index) use ($values): void {
            $index = $values[(int) $index];
        });
        array_walk_recursive($files, function (mixed &$index) use ($uploads): void {
            $index = $uploads[(int) $index];
        });
        return [$parsed, $files];
    }

    private static function header(string $head, string $name): ?string
    {
        foreach (preg_split('/\r?\n/', $head) as $line) {
            [$key, $value] = explode(':', $line, 2) + [1 => ''];
            if (strcasecmp(trim($key), $name) === 0) {
                return trim($value);
            }
        }
        return null;
    }

    /**
     * The parameters of a header value like `form-data; name="x"; filename=y`, read from left to right
     * so that a ; inside quotes does not start a new parameter. Inside quotes, as in PHP, only \" and
     * \\ count as escapes; names in lowercase, the first one wins.
     *
     * @return array<string, string>
     */
    private static function parameters(string $value): array
    {
        preg_match_all('/;\s*([^\s=;]+)\s*=\s*(?:"((?:[^"\\\\]|\\\\.)*)"|([^;]*))/', $value, $matches, PREG_SET_ORDER);
        $parameters = [];
        foreach ($matches as $match) {
            $parameters[strtolower($match[1])] ??= isset($match[3]) ? trim($match[3]) : preg_replace('/\\\\(["\\\\])/', '$1', $match[2]);
        }
        return $parameters;
    }

    /** Field names for parse_str: everything encoded except the brackets, which nest. */
    private static function encodeName(string $name): string
    {
        return str_replace(['%5B', '%5D'], ['[', ']'], rawurlencode($name));
    }
}

/**
 * A complete response with status, headers and body. A handler may also return a string, which becomes a
 * 200 with Content-Type text/plain. The static constructors build the usual kinds, header() and cookie()
 * add to it.
 */
final class Response
{
    /** @param array<string, string|list<string>> $headers several values as a list, for example for Set-Cookie */
    public function __construct(
        public string $body = '',
        public int $status = 200,
        public array $headers = ['content-type' => 'text/plain; charset=utf-8'],
    ) {}

    public static function json(mixed $data, int $status = 200): self
    {
        return new self(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n", $status, ['content-type' => 'application/json']);
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($html, $status, ['content-type' => 'text/html; charset=utf-8']);
    }

    /** Redirect; 302 (default), 303 after a POST, 301/308 for permanent ones. */
    public static function redirect(string $location, int $status = 302): self
    {
        return (new self('', $status, []))->header('location', $location);
    }

    /** Sets a header; with $append another value is added instead of replacing the existing one. */
    public function header(string $name, string $value, bool $append = false): self
    {
        checkHeader($name, $value);
        $key = strtolower($name);
        $this->headers[$key] = $append && isset($this->headers[$key]) ? [...(array) $this->headers[$key], $value] : $value;
        return $this;
    }

    /**
     * Sets a cookie like setcookie(): the value is encoded; without $expires (or with 0) it lasts until
     * the browser session ends. Defaults as is common today: HttpOnly and SameSite=Lax.
     */
    public function cookie(
        string $name,
        string $value,
        ?int $expires = null,
        string $path = '/',
        ?string $domain = null,
        bool $secure = false,
        bool $httpOnly = true,
        string $sameSite = 'Lax',
    ): self {
        // The same forbidden characters as setcookie(), plus the null byte
        $forbidden = "/[,; \t\r\n\013\014\0]/";
        if ($name === '' || str_contains($name, '=') || preg_match($forbidden, $name)) {
            throw new \ValueError("Invalid cookie name: $name");
        }
        if (preg_match($forbidden, $path . ($domain ?? ''))) {
            throw new \ValueError("Invalid path or domain in cookie $name");
        }
        if (!in_array($sameSite, ['', 'Lax', 'Strict', 'None'], true)) {
            throw new \ValueError("SameSite must be Lax, Strict, None or empty, not $sameSite");
        }
        // Browsers drop such a cookie without a word: say it here instead
        if ($sameSite === 'None' && !$secure) {
            throw new \ValueError("Cookie $name: SameSite=None needs secure: true, browsers drop it otherwise");
        }
        $cookie = "$name=" . rawurlencode($value);
        if ($expires) {
            $cookie .= '; Expires=' . gmdate('D, d M Y H:i:s', $expires) . ' GMT; Max-Age=' . max(0, $expires - time());
        }
        $cookie .= "; Path=$path";
        $cookie .= $domain !== null ? "; Domain=$domain" : '';
        $cookie .= $secure ? '; Secure' : '';
        $cookie .= $httpOnly ? '; HttpOnly' : '';
        $cookie .= $sameSite !== '' ? "; SameSite=$sameSite" : '';
        return $this->header('set-cookie', $cookie, append: true);
    }

    /**
     * A response whose body is produced bit by bit: the head goes out right away, every write() right
     * after it. The Fiber waits when the client cannot keep up.
     *
     * @param callable(Stream): void $writer
     * @param array<string, string|list<string>> $headers several values as a list, for example for Set-Cookie
     */
    public static function stream(callable $writer, int $status = 200, array $headers = ['content-type' => 'application/octet-stream']): StreamedResponse
    {
        return new StreamedResponse($writer(...), $status, $headers);
    }

    /**
     * Server-Sent Events: the browser reads along with `new EventSource(url)`.
     *
     * @param callable(EventStream): void $writer
     */
    public static function events(callable $writer): StreamedResponse
    {
        return new StreamedResponse(
            static fn (Stream $stream) => $writer(new EventStream($stream)),
            200,
            ['content-type' => 'text/event-stream', 'cache-control' => 'no-cache'],
        );
    }

    /**
     * Turns the connection into a WebSocket if the client asks for it; otherwise 426.
     *
     * @param callable(WebSocket): void $handler
     */
    public static function websocket(callable $handler): WebSocketResponse
    {
        return new WebSocketResponse($handler(...));
    }
}

/** A response whose body a writer produces while it is sent, from Response::stream() and Response::events(). */
final class StreamedResponse
{
    /** @param array<string, string|list<string>> $headers several values as a list, for example for Set-Cookie */
    public function __construct(
        public readonly \Closure $writer,
        public readonly int $status,
        public readonly array $headers,
    ) {}
}

/** Accepts the request as a WebSocket and runs the handler with the connection, from Response::websocket(). */
final class WebSocketResponse
{
    public function __construct(public readonly \Closure $handler) {}
}

/** The client is gone: streams and WebSockets throw this on the next write. */
final class ConnectionClosed extends \RuntimeException
{
}

/** Body of a streaming response. */
final class Stream
{
    public function __construct(private readonly int $rid) {}

    public function write(string $chunk): void
    {
        \Tenma\Internal\requireFiber();
        $result = \tenma_stream_write($this->rid, $chunk);
        try {
            $result > 0 && \Tenma\Internal\await($result);
        } catch (\RuntimeException) {
            $result = -1;
        }
        if ($result < 0) {
            throw new ConnectionClosed('The client closed the connection');
        }
    }
}

/** Server-Sent Events on a stream. */
final class EventStream
{
    public function __construct(private readonly Stream $stream) {}

    public function send(string $data, ?string $event = null, ?string $id = null): void
    {
        // EventSource breaks lines at \r too: a line break in id or event would forge fields
        if (strpbrk(($id ?? '') . ($event ?? ''), "\r\n") !== false) {
            throw new \InvalidArgumentException('id and event must not contain line breaks');
        }
        $message = ($id !== null ? "id: $id\n" : '') . ($event !== null ? "event: $event\n" : '');
        foreach (preg_split('/\r\n|\r|\n/', $data) as $line) {
            $message .= "data: $line\n";
        }
        $this->stream->write("$message\n");
    }

    /** A comment keeps the connection alive through proxies; the browser ignores it. */
    public function comment(string $text = ''): void
    {
        $this->stream->write(': ' . implode("\n: ", preg_split('/\r\n|\r|\n/', $text)) . "\n\n");
    }
}

/** An accepted WebSocket connection, passed to the handler of Response::websocket(). */
final class WebSocket
{
    public function __construct(private readonly int $rid) {}

    /**
     * Waits for the next message without blocking the server; null when the client has closed. Text and binary
     * messages both arrive as a string of their bytes.
     */
    public function receive(): ?string
    {
        \Tenma\Internal\requireFiber();
        $op = \tenma_ws_receive($this->rid);
        return $op === 0 ? null : \Tenma\Internal\await($op);
    }

    /**
     * Sends $data as a text message if it is valid UTF-8, otherwise as a binary one. Waits while the client
     * is behind, like Stream::write().
     */
    public function send(string $data): void
    {
        $result = \tenma_ws_send($this->rid, $data);
        try {
            $result > 0 && \Tenma\Internal\await($result);
        } catch (\RuntimeException) {
            $result = -1;
        }
        if ($result < 0) {
            throw new ConnectionClosed('The WebSocket is closed');
        }
    }

    public function close(): void
    {
        \tenma_ws_close($this->rid, true);
    }
}

/**
 * The rules hyper checks only after the handler: a token as the name, no control character but tab
 * in the value. Line breaks would also start a header of their own (response splitting).
 */
function checkHeader(string $name, string $value): void
{
    if (!preg_match('/\A[!#$%&\'*+.^_`|~0-9A-Za-z-]+\z/', $name) || preg_match('/[\x00-\x08\x0a-\x1f\x7f]/', $value)) {
        throw new \ValueError("Invalid header: $name");
    }
}

/**
 * @param array<string, string|list<string>> $headers
 * @return list<string>
 */
function headerLines(array $headers): array
{
    $lines = [];
    foreach ($headers as $name => $values) {
        foreach ((array) $values as $value) {
            checkHeader((string) $name, (string) $value);
            $lines[] = "$name: $value";
        }
    }
    return $lines;
}

/** @internal Behind Tenma::serve(). */
function serve(int $port, callable $handler, string|array|null $static = null): void
{
    $mounts = [];
    foreach (is_string($static) ? ['/' => $static] : $static ?? [] as $prefix => $path) {
        if (!is_string($prefix) || !str_starts_with($prefix, '/') || str_contains($prefix, '?') || !is_string($path)) {
            throw new \ValueError("static needs URL prefixes starting with / and paths, not $prefix");
        }
        $mounts[$prefix === '/' ? '/' : rtrim($prefix, '/')] = $path;
    }
    \tenma_listen($port, static function (array $raw, int $rid) use ($handler): void {
        // Every request as its own task: it responds as soon as the handler is done, and can be
        // cancelled together with all tasks it starts
        \Tenma\Internal\Current::$request = $rid;
        \Tenma\Task::start(static function () use ($handler, $raw, $rid): void {
            try {
                respond($rid, new Request($raw), $handler);
            } finally {
                // The response is out: what the request started ends with it, only detach() outlives it
                \Tenma\Internal\connectionEnded($rid);
            }
        }, false, null, $raw['method'] . ' ' . $raw['uri']);
    }, $mounts);
}

/** Runs the handler and sends what it returns: a response, a stream or a WebSocket. */
function respond(int $rid, Request $request, callable $handler): void
{
    // An exception in the handler ends only this request, not the server
    try {
        $response = $handler($request);
    } catch (\Tenma\Cancelled) {
        // Cancelled: after exit() or without a client the request is already answered, then this does nothing.
        // Otherwise (a cancelled task the handler was waiting for) the client gets 500
        \tenma_respond($rid, [500, ['Content-Type: text/plain; charset=utf-8'], "Internal Server Error\n"]);
        return;
    } catch (\Throwable $error) {
        error_log('Uncaught ' . $error);
        $response = new Response("Internal Server Error\n", 500);
    }
    // A wrong response too (for example a forgotten return) ends only this request
    try {
        match (true) {
            $response instanceof StreamedResponse => runStream($rid, $response),
            $response instanceof WebSocketResponse => runWebSocket($rid, $request, $response),
            $response instanceof Response => \tenma_respond($rid, [$response->status, headerLines($response->headers), $response->body]),
            is_string($response) => \tenma_respond($rid, [200, headerLines((new Response())->headers), $response]),
            default => throw new \TypeError('A Tenma::serve() handler must return Response, StreamedResponse, WebSocketResponse or string, not ' . get_debug_type($response)),
        };
    } catch (\Throwable $error) {
        error_log('Uncaught ' . $error);
        \tenma_respond($rid, [500, ['Content-Type: text/plain; charset=utf-8'], "Internal Server Error\n"]);
    }
}

function runStream(int $rid, StreamedResponse $response): void
{
    if (!\tenma_stream_start($rid, $response->status, headerLines($response->headers))) {
        return;
    }
    // Only a writer that returns ends the stream normally; otherwise the client sees it cut off
    $finished = false;
    try {
        ($response->writer)(new Stream($rid));
        $finished = true;
    } catch (ConnectionClosed | \Tenma\Cancelled) {
        // The client left or the request was cancelled; not an error for the server
    } catch (\Throwable $error) {
        error_log('Uncaught ' . $error);
    } finally {
        \tenma_stream_end($rid, $finished);
    }
}

function runWebSocket(int $rid, Request $request, WebSocketResponse $response): void
{
    if (strcasecmp($request->header('upgrade') ?? '', 'websocket') !== 0) {
        \tenma_respond($rid, [426, ['Content-Type: text/plain; charset=utf-8', 'Upgrade: websocket'], "Upgrade Required\n"]);
        return;
    }
    // RFC 6455 4.2: only a GET opens a WebSocket, and only version 13 exists
    if ($request->method !== 'GET') {
        \tenma_respond($rid, [405, ['Content-Type: text/plain; charset=utf-8', 'Allow: GET'], "Method Not Allowed\n"]);
        return;
    }
    if ($request->header('sec-websocket-version') !== '13') {
        \tenma_respond($rid, [426, ['Content-Type: text/plain; charset=utf-8', 'Sec-WebSocket-Version: 13'], "Upgrade Required\n"]);
        return;
    }
    if (!\tenma_ws_accept($rid)) {
        return;
    }
    $socket = new WebSocket($rid);
    // An error in the handler closes with 1011 instead of a normal close
    $finished = false;
    try {
        ($response->handler)($socket);
        $finished = true;
    } catch (ConnectionClosed | \Tenma\Cancelled) {
        // The client left or the request was cancelled; not an error for the server
    } catch (\Throwable $error) {
        error_log('Uncaught ' . $error);
    } finally {
        \tenma_ws_close($rid, $finished);
    }
}

/** Response from fetch(). */
final class FetchResponse
{
    /** @param list<string> $headers header lines "Name: Value" */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {}

    public function header(string $name): ?string
    {
        foreach ($this->headers as $line) {
            [$key, $value] = explode(':', $line, 2) + [1 => ''];
            if (strcasecmp($key, $name) === 0) {
                return trim($value);
            }
        }
        return null;
    }

    public function json(): mixed
    {
        return json_decode($this->body, true, flags: JSON_THROW_ON_ERROR);
    }
}

/** @internal Behind Tenma::fetch(). */
function fetch(string $url, string $method = 'GET', array $headers = [], string $body = '', float $timeout = 30.0): FetchResponse
{
    \Tenma\Internal\requireFiber();
    $lines = headerLines($headers);
    [$status, $responseHeaders, $responseBody] = \Tenma\Internal\await(\tenma_op_fetch($method, $url, $lines, $body, (int) ceil($timeout * 1000)));
    return new FetchResponse($status, $responseHeaders, $responseBody);
}

/** @internal Behind Tenma::serveClassic(). */
function serveClassic(int $port, callable $app): void
{
    $server = $_SERVER;
    // Superglobals, headers and output buffers exist only once: so classic code runs without a
    // Fiber, one request after another, and asynchronous functions throw here
    \tenma_listen($port, static function (array $raw, int $rid) use ($app, $server): void {
        $request = new Request($raw);
        startClassic($server, $request, $rid);
        try {
            $app($request->body);
        } catch (\Throwable $error) {
            error_log('Uncaught ' . $error);
            \Tenma\Internal\Current::$failed = true;
        }
        // Tenma takes over shutdown functions and the response once no PHP code is running: finishClassic()
    });
}

/**
 * Prepares a classic request like FPM: superglobals, php://input, uploads, output buffers.
 *
 * @param array<string, mixed> $server
 */
function startClassic(array $server, Request $request, int $rid): void
{
    \tenma_set_input($request->body);
    $contentType = $request->header('content-type') ?? '';
    $_GET = $request->query;
    $_POST = [];
    $_FILES = [];
    // Media types ignore case, as in PHP's SAPI
    if (stripos($contentType, 'application/x-www-form-urlencoded') === 0) {
        parse_str($request->body, $_POST);
    } elseif (stripos($contentType, 'multipart/form-data') === 0) {
        [$_POST, $_FILES] = \tenma_parse_multipart($contentType, $request->body);
    }
    // As under FPM, $_SESSION exists only after session_start(), never from the previous request
    unset($_SESSION);
    $_COOKIE = \tenma_parse_cookies($request->header('cookie') ?? '', true);
    // Like request_order=GP: POST overwrites GET, keys stay as they are
    $_REQUEST = array_replace($_GET, $_POST);
    $_SERVER = [
        ...$server,
        'REQUEST_METHOD' => $request->method,
        'REQUEST_URI' => $request->uri,
        'QUERY_STRING' => explode('?', $request->uri, 2)[1] ?? '',
        'SERVER_PROTOCOL' => 'HTTP/1.1',
        'SERVER_SOFTWARE' => 'tenma',
        'REQUEST_SCHEME' => 'http',
        'REMOTE_ADDR' => $request->remoteAddress,
        'REMOTE_PORT' => (string) $request->remotePort,
        'SERVER_ADDR' => $request->serverAddress,
        'SERVER_PORT' => (string) $request->serverPort,
        // The configured name (--server-name), never the Host header the client controls
        'SERVER_NAME' => $server['SERVER_NAME'] ?? 'localhost',
        // As under FPM with a front controller: the script sits in the site's root directory
        'DOCUMENT_ROOT' => dirname($server['SCRIPT_FILENAME']),
        'SCRIPT_NAME' => '/' . basename($server['SCRIPT_FILENAME']),
        'PHP_SELF' => '/' . basename($server['SCRIPT_FILENAME']),
        'REQUEST_TIME' => time(),
        'REQUEST_TIME_FLOAT' => microtime(true),
    ];
    foreach ($request->headers as $name => $value) {
        // Headers with an underscore are dropped, otherwise x_forwarded_for would overwrite X-Forwarded-For
        if (str_contains($name, '_')) {
            continue;
        }
        $key = strtoupper(str_replace('-', '_', $name));
        $_SERVER[in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : "HTTP_$key"] = $value;
    }

    \Tenma\Internal\Current::$classic = $rid;
    // From here on Tenma collects everything PHP outputs, even through flushed output buffers
    \tenma_classic_begin();
}

/**
 * What PHP otherwise does at the end of every request: save and close the session. Then Tenma forgets
 * its ID, otherwise the next visitor would take it over.
 */
function endSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    \tenma_forget_session();
}

/**
 * Answers the current classic request. Tenma calls this when no PHP code is running anymore and
 * the request's shutdown functions are done; their output still belongs to the response, as under FPM.
 * After an exception there is a 500 without the output so far, after a fatal error the output with 500.
 */
function finishClassic(bool $fatal): void
{
    $rid = \Tenma\Internal\Current::$classic;
    if ($rid === null) {
        return;
    }
    $failed = \Tenma\Internal\Current::$failed;
    \Tenma\Internal\Current::$classic = null;
    \Tenma\Internal\Current::$failed = false;
    $body = \tenma_take_output();
    endSession();

    if ($failed) {
        \tenma_respond($rid, [500, ['Content-Type: text/plain; charset=utf-8'], "Internal Server Error\n"]);
        return;
    }
    $headers = headers_list();
    if (!array_filter($headers, fn ($line) => stripos($line, 'content-type:') === 0)) {
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
    }
    $status = http_response_code() ?: 200;
    // tenma_respond() would throw here, outside the script: that would end the worker
    if ($status < 100 || $status > 999) {
        error_log("HTTP status $status is not between 100 and 999");
        $status = 500;
    }
    \tenma_respond($rid, [$fatal && $status === 200 ? 500 : $status, $headers, $body]);
}
}

namespace {
    /**
     * Tenma's API: an HTTP server and asynchronous work in Fibers. Classes live under `Tenma\`
     * (Tenma\Http\Request, Tenma\Task, Tenma\AsyncLocal, Tenma\Fs, Tenma\Db\Postgres).
     */
    final class Tenma
    {
        /**
         * Starts an HTTP server. It runs once the script has finished and keeps the process alive.
         * The handler gets every request and returns a Response or a string.
         * With $static, Tenma serves files itself (GET/HEAD, without PHP): ['/products' => __DIR__ . '/public/products',
         * '/favicon.svg' => __DIR__ . '/public/favicon.svg']. Under a prefix it looks in the directory; for
         * a file only its exact path matches; the longest prefix wins. Anything not found and all paths
         * outside go to the handler, the latter without touching the file system. A string means ['/' => $path]:
         * then every request is looked up, which costs PHP routes throughput. Files and directories
         * starting with a dot stay hidden.
         *
         * @param callable(\Tenma\Http\Request): (\Tenma\Http\Response|\Tenma\Http\StreamedResponse|\Tenma\Http\WebSocketResponse|string) $handler
         */
        public static function serve(int $port, callable $handler, string|array|null $static = null): void
        {
            \Tenma\Http\serve($port, $handler, $static);
        }

        /**
         * Starts an HTTP server for classic PHP code, for example a framework with its own front controller.
         * Before every call of $app, $_SERVER, $_GET, $_POST, $_FILES, $_COOKIE, $_REQUEST and php://input
         * are ready as under FPM; header(), http_response_code() and everything $app outputs become the response.
         * exit() and die() only end the request. $app also gets the raw body as an argument.
         *
         * @param callable(string $rawBody): mixed $app
         */
        public static function serveClassic(int $port, callable $app): void
        {
            \Tenma\Http\serveClassic($port, $app);
        }

        /**
         * Starts $work in its own Fiber and returns a task; await() fetches the result later.
         * Called at script startup, $work starts once the event loop is running: background work.
         *
         * @param callable(): mixed $work
         */
        public static function async(callable $work): \Tenma\Task
        {
            return \Tenma\async($work);
        }

        /**
         * Runs everything concurrently and returns the results with the same keys:
         * Tenma::all(product: fn () => …, weather: fn () => …). If one fails, all() cancels
         * the others, waits until they have cleaned up, and then throws the first error: nothing is left
         * behind in the background that still uses connections, transactions or the stream.
         *
         * @param callable(): mixed|\Tenma\Task ...$tasks
         * @return array<array-key, mixed>
         */
        public static function all(callable|\Tenma\Task ...$tasks): array
        {
            return \Tenma\all(...$tasks);
        }

        /**
         * Like async(), but the task belongs to no request: if the client leaves or the request ends with
         * exit(), it keeps running. For work that must not be left half done (payment and order,
         * email after the response). A waiter that gets cancelled only stops waiting.
         */
        public static function detach(callable $work): \Tenma\Task
        {
            return \Tenma\detach($work);
        }

        /**
         * Stops here when an editor is connected to tenma --inspect.
         * Does nothing otherwise.
         */
        public static function debugger(): void
        {
            \tenma_debugger();
        }

        /**
         * Under --watch: the file whose change made the script run again, null on the first run and without
         * --watch. A framework can tell from it whether only a part of the app changed.
         */
        public static function reloadedBy(): ?string
        {
            return \tenma_reloaded_by();
        }

        /**
         * Replaces the problems $source reports: the editor shows them at their line (through `tenma lsp`), and new
         * ones go to stderr as `path:line:column: error: message`, the form problem matchers and quickfix read. An
         * empty list clears them. Only under --watch, which also clears the script's lists when it reloads it;
         * otherwise it does nothing. $source names the reporter: 'waypoint-live', 'bakery:checks'. A relative file
         * counts from the working directory.
         *
         * @param list<array{file: string, line: int, message: string, severity?: 'error'|'warning'|'info', column?: int}> $list
         */
        public static function diagnostics(string $source, array $list): void
        {
            \Tenma\Internal\diagnostics($source, $list);
        }

        /**
         * Waits without blocking the server. Other requests keep running.
         */
        public static function sleep(float $seconds): void
        {
            \Tenma\sleep($seconds);
        }

        /**
         * Calls $callback once after $seconds seconds, in its own Fiber. Works
         * at script startup and in asynchronous code. A script without a server runs until all timers have
         * fired. With several workers, each worker schedules its own timers, also after every restart.
         */
        public static function setTimeout(callable $callback, float $seconds): \Tenma\Timer
        {
            return \Tenma\setTimeout($callback, $seconds);
        }

        /**
         * Calls $callback every $seconds seconds until cancel(); keeps a script without a server alive.
         */
        public static function setInterval(callable $callback, float $seconds): \Tenma\Timer
        {
            return \Tenma\setInterval($callback, $seconds);
        }

        /**
         * Sends $message to every subscription of $channel, in every worker of this process, this one
         * included, like PUBLISH in Redis. Messages are strings: workers share no PHP objects. A worker that
         * has not subscribed yet (still starting, restarting) misses the message.
         */
        public static function publish(string $channel, string $message): void
        {
            \tenma_pubsub('publish', 0, $channel, $message);
        }

        /**
         * Calls $callback with every message published to $channel, in its own Fiber, until cancel(). Works
         * at script startup and in asynchronous code; made in a request, it ends with the request, for
         * WebSockets and streams with the connection. Keeps a script without a server alive.
         *
         * @param callable(string): mixed $callback
         */
        public static function subscribe(string $channel, callable $callback): \Tenma\Subscription
        {
            return \Tenma\subscribe($channel, $callback);
        }

        /**
         * Outgoing HTTP request, without blocking the server. Throws on network errors and when the
         * response is not complete within $timeout seconds;
         * HTTP error codes are not errors; they are in $status.
         *
         * @param array<string, string> $headers
         */
        public static function fetch(string $url, string $method = 'GET', array $headers = [], string $body = '', float $timeout = 30.0): \Tenma\Http\FetchResponse
        {
            return \Tenma\Http\fetch($url, $method, $headers, $body, $timeout);
        }
    }
}
