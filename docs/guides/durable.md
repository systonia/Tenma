---
title: Durable functions
description: A function that survives crashes and restarts with Threading\Durable, waits for signals and timers for days, and goes on where it was.
order: 265
---

A durable function is plain sequential PHP that outlives the process it runs in. It may wait for a
payment for three days, sleep a day and send an email, and if tenma restarts or the machine dies
meanwhile, another process picks it up where it was. There is no state machine to write and no
`await`: the code reads like code that blocks.

```php
<?php

use Threading\{Durable, Signal, Task};

Durable::define('order', function (string $id) use ($orders, $mailer) {
    $order = $orders->reserve($id);
    $payment = Signal::receive("payment:$id", timeout: 3 * 86400);
    if ($payment === null || $payment['failed']) {
        $orders->release($id);
        return;
    }
    Task::delay(86400);
    $mailer->askForReview($order);
});

// In a handler:
Durable::start('order', $id);

// In the webhook of the payment provider:
Signal::send("payment:$id", ['failed' => false]);
```

tenma runs with `--durable postgres://user:pass@host/db`; the tables `tenma_durable`,
`tenma_journal`, `tenma_timers` and `tenma_signals` are created on first use.

## How it works

Every result the function gets from outside goes to its journal in Postgres before the function sees
it: the rows of a query, the body of a fetch, a file, a message, and the values of `time()`,
`random_int()` and the other functions a [replay](replay.md) covers. Resuming the function runs the
closure again from the start and answers each call from the journal, in order, until the journal is
used up; then it runs live and appends. The code between those calls runs again too: side effects the
journal cannot cover, such as an email through blocking SMTP or a file written with
`file_put_contents()`, happen at least once. Give them an idempotency key, or do them through an op
(`Http\Client`, `Data\Postgres`, `FileSystem\FileInfo`), which the journal repeats instead of running.

A wait of a second or more (`Task::delay()`) and every `Signal::receive()` end the fiber: a row in
`tenma_timers` says when, or on which signal, to wake the function. One worker per process polls the
table every second, appends the wait's result to the journal and resumes the function, so a wait
costs no memory and no fiber while it lasts.

One owner per key: a lease with an expiry, renewed every ten seconds while the function runs. A
process that dies loses its leases after thirty seconds, and the poller of any other process resumes
the function from its journal.

## The API

| Call                                                                                  | Does                                                                                                    |
| ------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------- |
| [`Durable::define($name, $work, version:)`](../reference/Threading/Durable.md#define) | registers the function under a name, at startup in every worker; `$work` gets the key                   |
| [`Durable::start($name, $key)`](../reference/Threading/Durable.md#start)              | runs the function once per key across every process; true if this call started it                       |
| [`Signal::send($name, $payload)`](../reference/Threading/Signal.md#send)              | wakes the function waiting for `$name`, in any process; kept for the next `receive()` if none waits yet |
| [`Signal::receive($name, timeout:)`](../reference/Threading/Signal.md#receive)        | inside a durable function: the payload of the next `send()`, or null after the timeout                  |
| `Task::delay($seconds)`                                                               | inside a durable function, a second or more: waits in the database                                      |

The payload of a signal is anything `json_encode()` takes.

## Versions

The closure of a function is found again by its name after a restart. When its code changes in a way
that changes which calls it makes, give it a higher `version:`. A journal recorded by version 1
refuses to run with version 2: the function ends with status `failed` and the error in
`tenma_durable.error`, instead of silently diverging. A journal of the same version whose code
diverges anyway fails the same way.

## Behaviour

- `Durable::start()` from a handler or a task takes the lease before it returns; at startup or from
  the poller it runs in a task of its own.
- A function ends with status `completed` or `failed`; its row stays, so a second start of the key
  does nothing. Delete the row to run it again.
- Inside a durable function `Task::delay()` below one second waits in memory, as everywhere else.
- A `catch (\Throwable)` in a durable function must rethrow `Tenma\Internal\Suspended`: it is how
  the fiber ends while the function waits in the database.
- `Signal::receive()` outside a durable function throws `LogicException`. `Signal::send()` works
  anywhere in a fiber.
- Durable functions need a running server, or a script that stays alive, and `--durable` in every
  process that defines them.

## See also

- [Record and replay](replay.md): the same journal, for a whole worker
- [Tasks, timers and AsyncLocal](async.md)
