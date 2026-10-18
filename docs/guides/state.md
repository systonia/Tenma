---
title: State in memory
description: What lives as long as the process, why per-request values do not belong in globals or statics, AsyncLocal, per-worker copies and shared state.
order: 130
---

The script runs once per worker, and what it builds stays in memory. Every request of that worker sees the same values. That makes startup work cheap, and it means per-request values need care.

## What lives as long as the worker

Everything the script creates at startup lives until the worker ends or restarts:

- variables of the script and values captured by the handler,
- globals, static properties and static variables,
- loaded classes, a router, a container, configuration,
- database clients and their connection pools, caches.

Build them once at startup, before `Tenma::serve()`:

```php
<?php

$config = ['greeting' => 'Hello'];   // built once per worker
$started = date('H:i:s');

Tenma::serve(8080, function () use ($config, $started) {
    return "{$config['greeting']}, this worker started at $started\n";
});
```

A request changes such values for every later request. `use (&$counter)` with a reference, a static or a global all keep their value from one request to the next.

## Per-request values

Do not keep per-request values in globals, statics or singletons. One worker runs many requests at the same time. When one request waits, another one runs and may overwrite the value.

```php
<?php

$current = null;   // wrong: shared by every request of the worker

Tenma::serve(8080, function () use (&$current) {
    $current = bin2hex(random_bytes(2));
    $mine = $current;

    Tenma::sleep(1);   // other requests run here and set $current

    return $mine === $current ? "still mine\n" : "overwritten\n";
});
```

Two parallel calls of `curl localhost:8080` print `overwritten` for the first one.

Pass per-request values along as arguments instead. For values that deep code needs, such as a request ID in log lines, use [`Tenma\AsyncLocal`](../reference/Tenma/AsyncLocal.md):

```php
<?php

use Tenma\AsyncLocal;

$requestId = new AsyncLocal();

Tenma::serve(8080, function () use ($requestId) {
    $requestId->set(bin2hex(random_bytes(2)));
    $mine = $requestId->get();

    Tenma::sleep(1);   // other requests set their own value

    return $mine === $requestId->get() ? "still mine\n" : "overwritten\n";
});
```

Every request prints `still mine`. Each request and each task has its own copy of the values. [Fibers and asynchronous work](async.md) documents the API.

## One copy per worker

Workers share no PHP values. With `--workers 4`, each worker runs the script and has its own copy of everything in memory:

- An in-memory counter counts the requests of one worker only.
- A cache exists once per worker, and each worker fills its own.
- Timers and background work from startup run once per worker.
- A restarted worker starts again with fresh state.

A per-worker copy is fine for data that every worker can rebuild, such as configuration or a cache of a slow web service.

## Shared state

State that all workers and all processes must see belongs outside the process:

- in Postgres or Redis ([Databases and messages](database.md)),
- in files ([Files](files.md)).

To tell the other workers that something changed, use `Tenma::publish()` and `Tenma::subscribe()`. A message reaches every worker of the process, and with `--pubsub redis://HOST` every process. Messages are strings: send what changed (an ID, JSON), and let each worker update its own copy. [Databases and messages](database.md) documents the API.

See also: [Process and workers](process.md), [Lifetime of a request](request-lifetime.md).
