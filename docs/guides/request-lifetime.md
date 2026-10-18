---
title: Lifetime of a request
description: What belongs to a request, when its work is cancelled, Tenma\Cancelled, work that outlives the request with Tenma::detach(), and exit() inside and outside a request.
order: 120
---

A request owns the work it starts. When the request ends, Tenma cancels that work, so nothing keeps running for a client that is gone. Work that must finish anyway runs in `Tenma::detach()`.

## What belongs to a request

These belong to the request whose code creates them:

- tasks from `Tenma::async()` and `Tenma::all()`,
- timers from `Tenma::setTimeout()` and `Tenma::setInterval()`, until they fire,
- subscriptions from `Tenma::subscribe()`.

Tasks, timers and subscriptions created at script startup belong to the worker instead. They run as long as the worker. A task from `Tenma::detach()` belongs to no request.

## When work is cancelled

Tenma cancels the work of a request when:

- the response is out (for a stream or a WebSocket: when the connection ends),
- the client disconnects before the response is complete,
- the request calls `exit()` or `die()`.

`Tenma::all()` also cancels its other functions when one of them fails. `$task->cancel()` cancels one task.

```php
<?php

use Tenma\Cancelled;

Tenma::serve(8080, function () {
    Tenma::async(function () {
        try {
            Tenma::sleep(5);
        } catch (Cancelled $e) {
            error_log('cancelled: ' . $e->getMessage());
        }
    });

    return "ok\n";
});
```

`curl localhost:8080` answers at once, and the log shows `cancelled: The connection ended`. A timer set in a request is cancelled the same way, so it never fires after the response.

## Tenma\Cancelled

Cancelling throws [`Tenma\Cancelled`](../reference/Tenma/Cancelled.md) where the fiber waits. A fiber that is not waiting gets it at its next wait.

- `catch` and `finally` blocks run, and they may wait to clean up.
- `Cancelled` extends `Exception`, not `RuntimeException`. A `catch (RuntimeException $e)` around a failing query does not swallow it.
- `$task->cancel($error)` throws `$error` instead of `Cancelled`.
- A `Cancelled` that leaves the handler gives the client a 500, if it is still there.

```php
<?php

use Tenma\Cancelled;

Tenma::serve(8080, function () {
    try {
        Tenma::sleep(5);

        return "done\n";
    } catch (Cancelled $e) {
        error_log('client left, cleaning up');
        Tenma::sleep(0.1);   // cleanup may wait
        error_log('cleanup done');

        throw $e;
    }
});
```

`curl -m 1 localhost:8080` gives up after one second. The handler gets `Cancelled` with the message `The client closed the connection`, and both log lines appear.

## Work after cancel

- A task that caught an earlier `$task->cancel()` gets `Cancelled` once more when its request ends.
- Each task gets that request-end cancellation only once, so its cleanup can wait.
- A task, timer or subscription that cleanup starts after the request ended is cancelled at once. A task gets `Cancelled` at its first wait.
- `COMMIT` and `ROLLBACK` of a Postgres transaction always run to completion.
- A running file operation of `Tenma\Fs` cannot be cancelled. The fiber gets `Cancelled`, and the operation still runs to completion on its thread.

## Work that must finish: Tenma::detach()

`Tenma::detach()` starts a task that belongs to no request. It keeps running when the response is out, when the client leaves and after `exit()`. Use it for work that must never stop halfway, such as storing a payment or sending a mail.

```php
<?php

use Tenma\Http\{Request, Response};

Tenma::serve(8080, function (Request $request) {
    $form = $request->form();

    Tenma::detach(function () use ($form) {
        Tenma::sleep(1);   // stands for the database and a mail server
        error_log('stored: ' . json_encode($form));
    });

    return Response::redirect('/thanks', 303);
});
```

The redirect goes out at once, and the log line follows a second later. A request that waits for a detached task with `await()` and gets cancelled only stops waiting. The task keeps running.

## exit() and die()

Inside a request, `exit()` and `die()` end only that request:

- The client gets a 500, if nothing was sent yet.
- Its streams, WebSockets and Postgres transactions are closed.
- Its tasks, timers and subscriptions are cancelled, and their `catch` and `finally` blocks run.
- The `finally` blocks of the fiber that called `exit()` do not run.
- The log shows `exit() in a Tenma::serve() handler only ends the request`.
- The worker keeps serving.

Outside a request, `exit($status)` ends Tenma with that status, also a running server. That covers code at script startup, background work started at startup and every timer or subscription callback. A timer callback runs outside its request even when a request set the timer.

In a script without a server, `exit()` at the top level also stops the timers and tasks the script scheduled: none of them starts.

In [classic mode](classic-mode.md), `exit()` and `die()` end only the request, and the output so far becomes the response.

## Errors at startup

- An uncaught exception or a fatal error in the script ends Tenma with status 255 on the first start.
- A fatal error in background work started at startup ends Tenma with status 255.
- An uncaught exception in a task is no fatal error. The task's `await()` throws it, and a task nobody awaits logs it.
- A script that fails when a worker restarts ends Tenma with status 1.
- Waiting at the top level of the script throws a `LogicException`. Wrap such work in `Tenma::async()`.

See also: [Event loop and fibers](event-loop.md), [Fibers and asynchronous work](async.md), [`Task`](../reference/Tenma/Task.md), [`Cancelled`](../reference/Tenma/Cancelled.md).
