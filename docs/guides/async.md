---
title: Tasks, timers and AsyncLocal
description: Run work concurrently with Tenma::async() and Tenma::all(), wait with Tenma::sleep(), schedule timers and carry values with AsyncLocal.
order: 220
---

These functions run work concurrently in one process. Each task and each timer callback runs in its own fiber. A call that waits suspends only its fiber and returns its result once it is there.

How fibers and the event loop work: [Event loop and fibers](event-loop.md). How cancellation works: [Lifetime of a request](request-lifetime.md).

| Function or class                                                              | Does                                                                                                |
| ------------------------------------------------------------------------------ | --------------------------------------------------------------------------------------------------- |
| [`Tenma::async($work)`](../reference/Tenma.md#async)                           | starts `$work` in its own fiber and returns a `Task` at once                                        |
| [`Tenma::all(...$work)`](../reference/Tenma.md#all)                            | runs functions or tasks concurrently and returns all results                                        |
| [`Tenma::sleep($seconds)`](../reference/Tenma.md#sleep)                        | waits without blocking the server                                                                   |
| [`Tenma::setTimeout($callback, $seconds)`](../reference/Tenma.md#settimeout)   | calls `$callback` once after `$seconds`                                                             |
| [`Tenma::setInterval($callback, $seconds)`](../reference/Tenma.md#setinterval) | calls `$callback` every `$seconds` until cancelled                                                  |
| [`Tenma::detach($work)`](../reference/Tenma.md#detach)                         | like `async()`, but the task outlives its request: see [Lifetime of a request](request-lifetime.md) |
| [`Task`](../reference/Tenma/Task.md)                                           | `await()`, `cancel()`, `isDone()`                                                                   |
| [`Timer`](../reference/Tenma/Timer.md)                                         | `cancel()`                                                                                          |
| [`AsyncLocal`](../reference/Tenma/AsyncLocal.md)                               | a value that follows a request into its tasks: `get()`, `set()`                                     |
| [`Cancelled`](../reference/Tenma/Cancelled.md)                                 | thrown where a cancelled fiber waits                                                                |

## Waiting

`Tenma::sleep()` suspends the calling fiber. Other requests and tasks run meanwhile.

```php
<?php

Tenma::serve(8080, function () {
    Tenma::sleep(0.5);

    return "half a second later\n";
});
```

PHP's own `sleep()` also works, but it holds up the whole worker. No other request of that worker runs while it waits.

## Tasks

`Tenma::async()` starts a function in its own fiber and returns a [`Task`](../reference/Tenma/Task.md) at once. `await()` returns its result later, or throws its error.

```php
<?php

Tenma::async(function () {
    $task = Tenma::async(function () {
        Tenma::sleep(0.2);   // stands for a slow query

        return 42;
    });

    echo "the task runs\n";       // the caller carries on meanwhile
    echo $task->await(), "\n";    // waits only now: 42
    var_dump($task->isDone());    // bool(true)
});
```

- `await()` waits without blocking and returns the result, or throws the task's error.
- `isDone()` tells whether the task has finished.
- `cancel()` cancels it: see [Cancellation](#cancellation).
- A task whose error nobody awaits logs the error when the task object goes away. Failures are not lost silently.

## Several things at once

`Tenma::all()` runs functions concurrently and returns their results under the same keys.

```php
<?php

Tenma::async(function () {
    $started = microtime(true);

    $results = Tenma::all(
        users: function () {
            Tenma::sleep(0.3);

            return ['ada', 'bob'];
        },
        count: function () {
            Tenma::sleep(0.2);

            return 2;
        },
    );

    printf("%d users after %.1f s\n", $results['count'], microtime(true) - $started);   // 0.3 s
});
```

- Named arguments give named results. A list (`Tenma::all(...$functions)`) gives a list.
- `all()` accepts tasks as well as functions.
- It takes as long as the slowest function, not the sum.
- If one function throws, `all()` cancels the others and waits until their `catch` and `finally` blocks have run. Then it throws the first error. Nothing is left running in the background.

## Cancellation

`$task->cancel()` throws [`Tenma\Cancelled`](../reference/Tenma/Cancelled.md) where the task waits. Its `catch` and `finally` blocks run.

```php
<?php

use Tenma\Cancelled;

Tenma::async(function () {
    $task = Tenma::async(function () {
        try {
            Tenma::sleep(10);
        } finally {
            echo "cleaning up\n";
        }
    });

    Tenma::sleep(0.1);
    $task->cancel();

    try {
        $task->await();
    } catch (Cancelled) {
        echo "cancelled\n";
    }
});
```

- `$task->cancel($error)` throws `$error` instead of `Cancelled`, for example a timeout exception of your own.
- If the task is not waiting right now, its next wait throws.
- Cancelling a task that is done does nothing.
- `Cancelled` is no `RuntimeException`. A `catch (RuntimeException $e)` around a failing query does not swallow it.

When a request's tasks and timers get cancelled on their own: [Lifetime of a request](request-lifetime.md).

## Timers

```php
<?php

$ticks = 0;

$interval = Tenma::setInterval(function () use (&$ticks) {
    echo "tick ", ++$ticks, "\n";
}, 0.5);

Tenma::setTimeout(function () use ($interval) {
    $interval->cancel();
    echo "done\n";
}, 2.2);
```

This prints four ticks, then `done`, and exits. A script without a server runs until no timers and no work are pending.

- `setTimeout()` calls its callback once, `setInterval()` until `cancel()`.
- Both return a [`Timer`](../reference/Tenma/Timer.md). `cancel()` stops it; a callback already running runs to completion.
- Every callback runs in its own fiber, so it may wait.
- Timers work at script startup and in asynchronous code.
- A timer set in a request is cancelled when the request ends. A timer set at startup belongs to the worker.

## Background work

`Tenma::async()` at script startup starts its work once the event loop runs. Together with a timer, this keeps a value fresh in memory, so requests do not wait for it.

```php
<?php

use Tenma\Http\Response;

$lastKnown = null;   // null until the first answer arrived

$refresh = function () use (&$lastKnown): void {
    try {
        $response = Tenma::fetch('https://httpbin.org/uuid', timeout: 2.5);
        if ($response->status === 200) {
            $lastKnown = $response->json()['uuid'];
        }
    } catch (RuntimeException|JsonException $e) {
        error_log("refresh: {$e->getMessage()}");   // keeps the old value
    }
};

Tenma::async($refresh);              // once at start
Tenma::setInterval($refresh, 60);    // then every minute

// use (&$lastKnown): an arrow function would capture a copy
Tenma::serve(8080, function () use (&$lastKnown) {
    return Response::json(['value' => $lastKnown]);
});
```

With several workers, each worker runs the script. Each schedules its own timers and background work, also after every restart. A cache like the one above then exists once per worker.

A job that must run once for the whole app needs more than a timer. Run it in a separate process, behind a lock in Redis, or in a database statement that claims the run.

## Values that follow a request

Globals are shared by all requests, so they cannot carry a per-request value such as a request ID. [`Tenma\AsyncLocal`](../reference/Tenma/AsyncLocal.md) can.

```php
<?php

use Tenma\AsyncLocal;
use Tenma\Http\Request;

$requestId = new AsyncLocal();

$log = function (string $message) use ($requestId): void {
    error_log("[{$requestId->get()}] $message");
};

Tenma::serve(8080, function (Request $request) use ($requestId, $log) {
    $requestId->set($request->header('x-request-id') ?? bin2hex(random_bytes(4)));
    $log("start {$request->path}");

    Tenma::all(
        fn () => $log('first task: same ID'),
        fn () => $log('second task: same ID'),
    );

    return "ok\n";
});
```

- `get()` returns the value, or null if none is set.
- `set($value)` sets it; `set(null)` removes it.
- Every task, timer and `detach()` gets a copy of its creator's values when it starts.
- `set()` inside a task changes only its own copy and that of tasks it starts later.
- A request starts with the values the script set at startup.

## Behaviour

- `sleep()`, `await()` and `all()` work only in asynchronous code: handlers, tasks, timers, subscriptions. At the top level of a script they throw a `LogicException`. Wrap startup work that waits in `Tenma::async()`.
- `async()`, `setTimeout()` and `setInterval()` also work at script startup. Their work starts once the event loop runs.
- A task that awaits itself throws a `LogicException`.
- A fiber only gives way when it waits. A CPU-heavy loop holds up its worker until it ends.
- An error in a timer callback or a task nobody awaits goes to the log.
- Tasks, timers and subscriptions started in a request end with it. Only `detach()` outlives it: see [Lifetime of a request](request-lifetime.md).

See also: [`Tenma`](../reference/Tenma.md), [`Task`](../reference/Tenma/Task.md), [`Timer`](../reference/Tenma/Timer.md), [`Cancelled`](../reference/Tenma/Cancelled.md), [`AsyncLocal`](../reference/Tenma/AsyncLocal.md).
