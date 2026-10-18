---
title: Fibers and asynchronous work
description: Wait without blocking, run work in parallel with tasks and all(), use timers, cancel work and carry values with AsyncLocal.
order: 20
---

Every request, task and timer callback in Tenma runs in its own fiber. When code in a fiber waits for something Tenma does asynchronously, only that fiber is suspended; the event loop keeps serving everything else. There is no `await` keyword and no promise type: the call returns its result once it is there.

```php
<?php

Tenma::serve(8080, function () {
    Tenma::sleep(0.5);                                                      // other requests run meanwhile
    $answer = Tenma::fetch('https://date.nager.at/api/v3/NextPublicHolidays/DE');   // so do they here
    return "the holiday service answered {$answer->status}\n";
});
```

What waits without blocking:

| Call                                                                          | Guide                                              |
| ----------------------------------------------------------------------------- | -------------------------------------------------- |
| `Tenma::sleep($seconds)`                                                      | this page                                          |
| `Tenma::fetch($url, …)`                                                       | [Outgoing HTTP](fetch.md)                          |
| `Tenma\Db\Postgres`, `Tenma\Db\Redis`                                         | [Databases and messages](database.md)              |
| `Tenma\Fs::readFile()` and the other file functions                           | [Files](files.md)                                  |
| `Stream::write()`, `EventStream::send()`, `WebSocket::receive()` and `send()` | [Streams](streams.md), [WebSockets](websockets.md) |
| `Task::await()`, `Tenma::all()`                                               | this page                                          |

PHP's own blocking functions (`sleep()`, `file_get_contents()`, PDO, mysqli, curl) still work, but they hold up the whole worker while they wait: no other request of that worker runs meanwhile. Use the Tenma functions in request code.

## Several things at once

`Tenma::all()` runs functions concurrently and returns their results under the same keys:

```php
<?php

Tenma::serve(8080, function () {
    $started = microtime(true);
    ['holidays' => $holidays, 'weather' => $weather] = Tenma::all(
        holidays: fn () => Tenma::fetch('https://date.nager.at/api/v3/NextPublicHolidays/DE')->json(),
        weather: fn () => Tenma::fetch('https://api.open-meteo.com/v1/forecast?latitude=47.99&longitude=7.85&daily=temperature_2m_max')->json(),
    );
    return sprintf("%d holidays, %d days of forecast after %.2f s\n",   // as long as the slower one, not both
        count($holidays), count($weather['daily']['time']), microtime(true) - $started);
});
```

The bakery's product page asks four sources at once: the product and the opening hours from Postgres, the public holidays and the weather from two web services. Each closure fills its own variable; the page takes as long as the slowest source:

```php
// from src/Controllers/ShopController.php of the bakery, product(), shortened
$product = $holidays = $weather = null;
$hours = [];
\Tenma::all(
    function () use (&$product, $slug): void { $product = $this->products->bySlug($slug); },
    function () use (&$hours): void { $hours = $this->hours->week(); },
    function () use (&$holidays): void { $holidays = $this->holidays->upcoming(); },
    function () use (&$weather): void { $weather = $this->weather->week(); },
);
```

`all()` also takes a list (`Tenma::all(...$functions)`) and returns a list then. If one of the functions throws, `all()` cancels the others, waits until their `catch` and `finally` blocks have run, and then throws the first error. Nothing is left running in the background that still holds a connection or a transaction.

## Tasks

`Tenma::async()` starts one function in its own fiber and returns a [`Task`](../reference/Tenma/Task.md) at once. `await()` returns its result later, or throws its error:

```php
<?php

Tenma::serve(8080, function () {
    $sales = Tenma::async(function () {
        Tenma::sleep(0.2);   // stands for the query behind the sales report
        return ['croissant' => 312, 'pretzel' => 287];
    });

    $header = "Sales of the last 28 days\n";   // carries on while the task runs
    $sold = $sales->await();                    // waits for it only now
    return $header . "croissants: {$sold['croissant']}, pretzels: {$sold['pretzel']}\n";
});
```

- `$task->isDone()` tells whether it has finished.
- `$task->cancel()` cancels it (see below).
- A task whose error nobody ever awaits logs the error when the task object goes away, so failures are not lost silently.
- `Tenma::all()` accepts tasks as well as functions.

## Timers

```php
<?php

$batches = 0;
$oven = Tenma::setInterval(function () use (&$batches) {
    echo "batch ", ++$batches, " of rolls is out\n";
}, 0.5);

Tenma::setTimeout(function () use ($oven) {
    $oven->cancel();
    echo "oven off\n";
}, 2.2);
```

This script prints four batches, then `oven off`, and exits: a script without a server runs until no timers and no work are pending. `setTimeout()` calls its callback once, `setInterval()` until `cancel()`. Both return a [`Timer`](../reference/Tenma/Timer.md), and every callback runs in its own fiber, so it may wait.

Timers set at script startup belong to the process (or the worker); timers set in a request belong to that request and end with it.

## Background work

Work started at script startup with `Tenma::async()` runs as soon as the event loop starts, in the background. The bakery's product page needs the public holidays and the weather, and asking two web services on every request would make it as slow as they are. Timers keep their last good answers in the worker's memory instead, so the page only waits for Postgres:

```php
<?php

use Tenma\Http\Response;

// The last good answers; null until the first one arrived
$lastKnown = ['holidays' => null, 'weather' => null];

$refresh = function (string $name, string $url) use (&$lastKnown): void {
    try {
        $response = Tenma::fetch($url, timeout: 2.5);
        if ($response->status === 200) {
            $lastKnown[$name] = $response->json();
        }
    } catch (RuntimeException|JsonException $e) {
        error_log("$name: {$e->getMessage()}");   // keeps what was there
    }
};

// Once at start, then every interval: the holidays daily, the weather every 15 minutes
$every = function (float $seconds, Closure $job): void {
    Tenma::async($job);
    Tenma::setInterval($job, $seconds);
};
$every(86400, fn () => $refresh('holidays', 'https://date.nager.at/api/v3/NextPublicHolidays/DE'));
$every(900, fn () => $refresh('weather', 'https://api.open-meteo.com/v1/forecast?latitude=47.99&longitude=7.85&daily=weather_code,temperature_2m_max'));

// A closure with use (&$lastKnown): an arrow function (fn) would capture a copy
Tenma::serve(8080, function () use (&$lastKnown) {
    return Response::json($lastKnown);   // answers from memory, without waiting for either service
});
```

The bakery does the same with a service object, `LastKnown`, whose refresh methods the timers in its `index.php` call:

```php
// from src/Services/LastKnown.php of the bakery: a failed refresh keeps what was there
public function refreshWeather(): void
{
    $this->weather = $this->weatherService->week() ?? $this->weather;
}
```

Waiting at the top level of the script is not possible: `Tenma::sleep()` there throws. Wrap startup work that has to wait in `Tenma::async()`.

With several workers, each worker runs the script, so each schedules its own timers and background work, also after every restart. A cache like the one above then exists once per worker, which is fine for a forecast. A job that must run once for the whole app belongs in a separate process, behind a lock in Redis, or in a statement that claims its run in the database: the bakery's sales report moves a `last_run` timestamp only if it is older than the interval, in the same SQL statement that writes the sales, so a second worker at the same moment writes nothing (`SalesReport::aggregate()` in `src/Repositories/SalesReport.php`).

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
    $forecast = Tenma::async(function () {
        try {
            Tenma::sleep(10);   // stands for a weather service on a very bad day
            return 'sunny';
        } catch (Cancelled $e) {
            error_log('forecast cancelled, cleaning up');
            throw $e;
        }
    });
    Tenma::sleep(0.1);
    $forecast->cancel();               // throws Cancelled inside the task, where it sleeps
    try {
        $forecast->await();
    } catch (Cancelled) {
        return "no forecast today\n";
    }
});
```

`$task->cancel($error)` throws `$error` instead of `Cancelled`, for example a timeout exception of your own.

### Work that must finish: detach

Some work must never stop halfway because the customer closed the tab: charging a card and storing the order, sending the confirmation mail. Start it with `Tenma::detach()`: the task belongs to no request and keeps running when the request ends.

```php
<?php

use Tenma\Http\{Request, Response};

Tenma::serve(8080, function (Request $request) {
    $order = $request->form();
    Tenma::detach(function () use ($order) {
        Tenma::sleep(1);   // stands for the payment provider and the database
        error_log('order stored: ' . json_encode($order));
    });
    return Response::redirect('/orders/thanks', 303);   // answers at once; the order is stored anyway
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
    logLine($requestId, "product page {$request->path}");
    Tenma::all(
        fn () => logLine($requestId, 'asking for the holidays: same ID'),
        fn () => logLine($requestId, 'asking for the weather: same ID'),
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
