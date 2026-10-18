<?php

namespace Tenma;

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

/** @internal Behind Tenma::subscribe(). */
function subscribe(string $channel, callable $callback): Subscription
{
    Internal\Loop::$running && Internal\requireFiber();
    $subscription = new Subscription($channel, $callback(...));
    Internal\whenRunning($subscription->arm(...));
    return $subscription;
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
