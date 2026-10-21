---
title: Process and workers
description: One process, a main thread that accepts connections, worker threads with their own interpreter and event loop, restarts, and scripts without a server.
order: 100
---

A Tenma app is one process. Its main thread accepts connections, and its worker threads run PHP. Each worker has its own PHP interpreter and its own event loop.

## Startup

1. The main thread starts PHP.
2. The first worker gets its own interpreter, loads Tenma's PHP API and runs the script.
3. If the script registered no server, it is an ordinary script (see [Scripts without a server](#scripts-without-a-server)).
4. Otherwise the other workers start and run the script too.
5. The main thread opens the ports and accepts connections.

`Http\Server::start()` only registers the handler. The port opens after the script has finished. A server listens on every interface (`0.0.0.0`).

## Workers

```bash
tenma --workers 4 app.php
```

- `--workers N` (or `-w N`) starts N workers. The default is 1, and `auto` starts one per CPU core.
- Each worker is a thread with its own interpreter and its own event loop.
- The script runs once per worker. Each worker has its own copy of everything the script builds.
- The main thread hands connections to the workers in turn (round robin). A connection stays with its worker.

One worker serves many concurrent requests in fiber mode. Start with one worker per core and measure. In [classic mode](classic-mode.md) a worker serves one request at a time, so more workers mean more parallel requests.

## State per worker

Workers share no PHP values. A counter, a cache or a connection pool exists once per worker:

```php
<?php

$hits = 0;
$worker = bin2hex(random_bytes(2));

new Http\Server(8080, function () use (&$hits, $worker) {
    $hits++;

    return "worker $worker, request $hits\n";
})->start();
```

With `tenma --workers 2 app.php`, four calls of `curl localhost:8080` print `request 1` twice and `request 2` twice, from two different workers. [State in memory](state.md) explains what to keep in memory and what to share.

## Restarts

A worker restarts its interpreter and runs the script again in these cases:

- **A fatal PHP error**, such as running out of memory or a stack overflow. The request gets a 500. The other workers keep serving.
- **`--max-requests N`**: after N requests. This helps against code that leaks memory.
- **`--watch`**: a file the script loaded has changed ([Watch mode](watch.md)).

A restarted worker starts with fresh state: the script runs again from the top. If the script fails during a restart, Tenma exits with status 1.

An exception in a handler is no fatal error. It gives a 500, and the worker keeps running.

## Signals

- **Ctrl+C or SIGTERM**: Tenma stops accepting connections and lets running requests finish for up to 10 seconds, then exits.
- **A second signal** ends Tenma at once.

PHP's own signal handlers are disabled, and `pcntl` is not built in. Signals belong to Tenma.

## Scripts without a server

A script that never starts an `Http\Server` or `Http\ClassicServer` is an ordinary script:

```php
<?php

use Threading\Timer;

Timer::after(0.5, fn () => print("done\n"));

echo "waiting\n";
```

- It runs once, in one worker.
- It keeps running while timers, tasks or subscriptions are pending, then exits.
- `exit($status)` ends Tenma with that status.
- A fatal error ends Tenma with status 255.

Everything after the script on the command line ends up in `$argv`: `tenma app.php db:migrate` runs a CLI task with the same code that serves.

See also: [Command line and deployment](deployment.md), [Lifetime of a request](request-lifetime.md).
