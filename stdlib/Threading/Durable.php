<?php

namespace Threading;

/**
 * A function that survives crashes, restarts and deployments: written as plain sequential code, it may
 * wait for a signal or a timer for days and goes on where it was, in whichever process runs. Every
 * result it gets from outside (a query, a fetch, time(), random_int()) goes to its journal in Postgres
 * (`tenma --durable postgres://…`) before it sees it; resuming runs the code again and answers from the
 * journal until the code is past what it did before.
 *
 * Side effects the journal cannot cover (an email through blocking SMTP) run at least once: give them
 * an idempotency key. A durable function needs a running server, or a script that stays alive.
 */
final class Durable
{
    /** Task::delay() of this many seconds or more inside a durable function waits in the database. */
    public const float TIMER = 1.0;

    /** @var array<string, array{\Closure, int}> */
    private static array $definitions = [];

    /**
     * Defines the durable function $name: $work gets the key it was started with. Define it at startup, in
     * every worker; a process that resumes the function finds it by name. Code that changes between
     * versions gives a higher $version: a journal of another version fails loud instead of diverging.
     *
     * @param callable(string $key): mixed $work
     */
    public static function define(string $name, callable $work, int $version = 1): void
    {
        if (preg_match('/^[\w.:-]+$/', $name) !== 1 || $version < 1) {
            throw new \InvalidArgumentException("Durable::define(): '$name' is no name, or the version is below 1");
        }
        self::$definitions[$name] = [$work(...), $version];
    }

    /**
     * Starts the function $name once for $key, across every process: a second start with a running or
     * finished key does nothing. Returns whether this call started it.
     */
    public static function start(string $name, string $key): bool
    {
        return self::resume($name, $key);
    }

    /** @internal Runs or resumes the function if its lease is free; the poller calls this too. */
    public static function resume(string $name, string $key): bool
    {
        [$work, $version] = self::$definitions[$name] ?? throw new \LogicException("Durable function $name is not defined");
        $launch = function () use ($name, $key, $work, $version): bool {
            try {
                $answer = \Tenma\Internal\await(\tenma_op_durable('start', $key, $name, (float) $version));
            } catch (\RuntimeException $error) {
                error_log("tenma: durable function $name for $key: " . $error->getMessage());
                return false;
            }
            if ($answer !== 'run') {
                return false;
            }
            Task::start(static function () use ($key, $work, $name): void {
                try {
                    $work($key);
                    \Tenma\Internal\await(\tenma_op_durable('finish', $key, '', 0));
                } catch (\Tenma\Internal\Suspended) {
                    // Waiting in the database: the function runs again once the wait is over
                } catch (\Throwable $error) {
                    error_log("tenma: durable function $name for $key failed: " . $error);
                    try {
                        \Tenma\Internal\await(\tenma_op_durable('finish', $key, $error->getMessage(), 0));
                    } catch (\Throwable) {
                    }
                }
            }, null, null, "Durable $name:$key", $key);
            return true;
        };
        if (\Fiber::getCurrent() !== null) {
            // From a handler or a task: the claim waits here, the function runs on its own
            return $launch();
        }
        // From the poller or at startup: in a task of its own, once the loop runs
        if (\Tenma\Internal\Loop::$running) {
            Task::start($launch, null, null, "Durable $name");
        } else {
            Task::run($launch);
        }
        return false;
    }

    /**
     * @internal The function waits for $what ("timer" or "signal:<name>") until $due (a Unix time). Returns
     * the result if the journal already has it or the signal was sent before; otherwise ends the fiber.
     */
    public static function wait(string $what, float $due): ?string
    {
        $key = \Tenma\Internal\Current::$durable ?? throw new \LogicException('Only a durable function can wait like this');
        $answer = \Tenma\Internal\await(\tenma_op_durable('wait', $key, $what, $due));
        if ($answer === 'suspend') {
            throw new \Tenma\Internal\Suspended("durable function $key waits for $what");
        }
        return $answer;
    }
}
