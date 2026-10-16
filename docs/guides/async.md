---
title: Fibers and asynchronous work
description: Wait without blocking, run work in parallel with tasks and all(), use timers, cancel work and carry values with AsyncLocal.
order: 20
---

Every request, task and timer callback in Tenma runs in its own fiber. When code in a fiber waits for something Tenma does asynchronously, only that fiber is suspended; the event loop keeps serving everything else. There is no `await` keyword and no promise type: the call returns its result once it is there.

```php
<?php

Tenma::serve(8080, function () {
    Tenma::sleep(0.5);                                       // other requests run meanwhile
    $answer = Tenma::fetch('https://example.com/');          // so do they here
    return "example.com answered {$answer->status}\n";
});
```

What waits without blocking:

| Call | Guide |
|---|---|
| `Tenma::sleep($seconds)` | this page |
| `Tenma::fetch($url, …)` | [Outgoing HTTP](fetch.md) |
| `Tenma\Db\Postgres`, `Tenma\Db\Redis` | [Databases and messages](database.md) |
| `Tenma\Fs::readFile()` and the other file functions | [Files](files.md) |
| `Stream::write()`, `EventStream::send()`, `WebSocket::receive()` and `send()` | [Streams](streams.md), [WebSockets](websockets.md) |
| `Task::await()`, `Tenma::all()` | this page |

PHP's own blocking functions (`sleep()`, `file_get_contents()`, PDO, mysqli, curl) still work, but they hold up the whole worker while they wait: no other request of that worker runs meanwhile. Use the Tenma functions in request code.

## Several things at once

`Tenma::all()` runs functions concurrently and returns their results under the same keys:

```php
<?php

Tenma::serve(8080, function () {
    $started = microtime(true);
    ['a' => $a, 'b' => $b] = Tenma::all(
        a: function () { Tenma::sleep(0.3); return 'first'; },
        b: function () { Tenma::sleep(0.3); return 'second'; },
    );
    return sprintf("%s and %s after %.1f s\n", $a, $b, microtime(true) - $started);   // about 0.3 s, not 0.6
});
```

`all()` also takes a list (`Tenma::all(...$functions)`) and returns a list then. If one of the functions throws, `all()` cancels the others, waits until their `catch` and `finally` blocks have run, and then throws the first error. Nothing is left running in the background that still holds a connection or a transaction.

## Tasks

`Tenma::async()` starts one function in its own fiber and returns a [`Task`](../reference/Tenma/Task.md) at once. `await()` returns its result later, or throws its error:

```php
<?php

Tenma::serve(8080, function () {
    $report = Tenma::async(function () {
        Tenma::sleep(0.2);   // stands for a slow query
        return ['rows' => 42];
    });

    $header = "Report\n";                 // carries on while the task runs
    $rows = $report->await()['rows'];     // waits for it only now
    return "$header$rows rows\n";
});
```

- `$task->isDone()` tells whether it has finished.
- `$task->cancel()` cancels it (see below).
- A task whose error nobody ever awaits logs the error when the task object goes away, so failures are not lost silently.
- `Tenma::all()` accepts tasks as well as functions.

## Timers

```php
<?php

$ticks = 0;
$timer = Tenma::setInterval(function () use (&$ticks) {
    echo "tick ", ++$ticks, "\n";
}, 0.5);

Tenma::setTimeout(function () use ($timer) {
    $timer->cancel();
    echo "stopped\n";
}, 2.2);
```

This script prints four ticks, then `stopped`, and exits: a script without a server runs until no timers and no work are pending. `setTimeout()` calls its callback once, `setInterval()` until `cancel()`. Both return a [`Timer`](../reference/Tenma/Timer.md), and every callback runs in its own fiber, so it may wait.

Timers set at script startup belong to the process (or the worker); timers set in a request belong to that request and end with it.

## Background work

Work started at script startup with `Tenma::async()` runs as soon as the event loop starts, in the background:

```php
<?php

use Tenma\Http\Response;

$stats = ['warm' => false, 'checked' => null];
Tenma::async(function () use (&$stats) {
    Tenma::sleep(0.2);   // e.g. load a cache from the database
    $stats['warm'] = true;
});
Tenma::setInterval(function () use (&$stats) {
    $stats['checked'] = date('H:i:s');
}, 1.0);

// A closure with use (&$stats): an arrow function (fn) would capture a copy
Tenma::serve(8080, function () use (&$stats) {
    return Response::json($stats);
});
```

Waiting at the top level of the script is not possible: `Tenma::sleep()` there throws. Wrap startup work that has to wait in `Tenma::async()`.

With several workers, each worker runs the script, so each schedules its own timers and background work, also after every restart. A job that must run once for the whole app belongs in a separate process or behind a lock in Redis.

## Cancellation

Work belongs to the request that started it. Tasks, timers and subscriptions of a request are cancelled when:

- the response is out (for a stream or WebSocket: when the connection ends),
- the client disconnects before the response is complete,
- the request calls `exit()`,
- or a sibling in `Tenma::all()` failed.

Cancelling throws [`Tenma\Cancelled`](../reference/Tenma/Cancelled.md) where the fiber currently waits; `catch` and `finally` blocks run, and may even wait to clean up. `Cancelled` is deliberately no `RuntimeException`, so a `catch (RuntimeException $e)` around a failing query does not swallow it.

```php
<?php

use Tenma\Cancelled;

Tenma::serve(8080, function () {
    $slow = Tenma::async(function () {
        try {
            Tenma::sleep(10);
            return 'finished';
        } catch (Cancelled $e) {
            error_log('slow task cancelled, cleaning up');
            throw $e;
        }
    });
    Tenma::sleep(0.1);
    $slow->cancel();                 // throws Cancelled inside the task, where it sleeps
    try {
        $slow->await();
    } catch (Cancelled) {
        return "gave up waiting\n";
    }
});
```

`$task->cancel($error)` throws `$error` instead of `Cancelled`, for example a timeout exception of your own.

### Work that must finish: detach

Some work must never stop halfway because the visitor closed the tab: charging a card and storing the order, sending the confirmation mail. Start it with `Tenma::detach()`: the task belongs to no request and keeps running when the request ends.

```php
<?php

use Tenma\Http\{Request, Response};

Tenma::serve(8080, function (Request $request) {
    $order = $request->form();
    Tenma::detach(function () use ($order) {
        Tenma::sleep(1);   // stands for the payment provider and the database
        error_log('order stored: ' . json_encode($order));
    });
    return Response::redirect('/thanks', 303);   // answers at once; the order is stored anyway
});
```

`COMMIT` and `ROLLBACK` of a Postgres transaction always run to completion, also in a cancelled request.

## Values that follow a request

Globals are shared by all requests, so they cannot carry per-request values such as a request ID. [`Tenma\AsyncLocal`](../reference/Tenma/AsyncLocal.md) can: every task, timer and `detach()` gets a copy of its creator's values when it starts, and `set()` inside a task changes only its own copy.

```php
<?php

use Tenma\AsyncLocal;
use Tenma\Http\Request;

$requestId = new AsyncLocal();

function logLine(AsyncLocal $requestId, string $message): void
{
    error_log("[{$requestId->get()}] $message");
}

Tenma::serve(8080, function (Request $request) use ($requestId) {
    $requestId->set($request->header('x-request-id') ?? bin2hex(random_bytes(4)));
    logLine($requestId, 'request started');
    Tenma::all(
        fn () => logLine($requestId, 'in a task: same ID'),
        fn () => logLine($requestId, 'in another task: same ID'),
    );
    return "ok\n";
});
```

A request starts with the values the script set at startup.

## Rules of thumb

- Start independent waits together (`Tenma::all()`), dependent ones one after another.
- Keep CPU-heavy loops short: a fiber only gives way when it waits. An endless loop holds up its worker.
- Use `Tenma::detach()` only for work that must outlive the request; everything else should end with it.
- Waiting is possible in handlers, tasks, timers, subscriptions and hooks, but not at the top level of the script.

See also: [`Tenma`](../reference/Tenma.md) (`async`, `all`, `detach`, `sleep`, `setTimeout`, `setInterval`), [`Task`](../reference/Tenma/Task.md), [`Timer`](../reference/Tenma/Timer.md), [`Cancelled`](../reference/Tenma/Cancelled.md), [`AsyncLocal`](../reference/Tenma/AsyncLocal.md).
