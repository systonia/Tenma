---
title: Event loop and fibers
description: How one worker serves many requests at once, what waits without blocking, and what blocks the worker.
order: 110
---

Each worker runs one event loop. Every request, task, timer callback and subscription callback runs in its own [fiber](https://www.php.net/manual/en/language.fibers.php). A fiber that waits is suspended, and the event loop runs the other fibers meanwhile.

## How waiting works

1. A handler calls a function that waits, such as `Tenma::sleep()`.
2. Tenma starts an op for it outside PHP, and the fiber suspends.
3. The event loop runs other requests meanwhile.
4. When the op is done, the event loop resumes the fiber with the result.

There is no `await` keyword and no promise type. A function that waits simply returns its result:

```php
<?php

Tenma::serve(8080, function () {
    Tenma::sleep(1);   // only this request waits

    $response = Tenma::fetch('https://httpbin.org/get');

    return "httpbin answered {$response->status}\n";
});
```

## Waiting and blocking

Code waits when it calls a Tenma function that waits. Other requests of the worker keep running.

Code blocks when it calls one of PHP's own functions that wait, such as `sleep()`, `file_get_contents()`, PDO, mysqli or curl. They work, but no other request of that worker runs until they return.

```php
<?php

use Tenma\Http\Request;

Tenma::serve(8080, function (Request $request) {
    if ($request->path === '/blocking') {
        sleep(1);   // holds up the whole worker
    } else {
        Tenma::sleep(1);   // suspends this fiber only
    }

    return "done\n";
});
```

Five parallel calls to `/` finish after about one second. Five parallel calls to `/blocking` take about five seconds with one worker.

A fiber also gives way only when it waits. A long loop that computes holds up its worker, and an endless loop holds it up for good.

## Calls that wait without blocking

| Call                                                                     | Page                                     |
| ------------------------------------------------------------------------ | ---------------------------------------- |
| `UploadedFile::moveTo()`                                                 | [Serving HTTP](http.md)                  |
| `Tenma::fetch()`                                                         | [Outgoing HTTP](fetch.md)                |
| `Tenma::sleep()`, `Task::await()`, `Tenma::all()`                        | [Fibers and asynchronous work](async.md) |
| `Stream::write()`, `EventStream::send()`, `EventStream::comment()`       | [Streams](streams.md)                    |
| `WebSocket::receive()`, `WebSocket::send()`                              | [WebSockets](websockets.md)              |
| `Tenma\Fs::readFile()`, `writeFile()` and every other `Fs` function      | [Files](files.md)                        |
| `Postgres::query()`, `first()`, `execute()`, `script()`, `transaction()` | [Databases and messages](database.md)    |
| `Redis::command()`, `get()`, `set()`                                     | [Databases and messages](database.md)    |

## Where code can wait

- In a `Tenma::serve()` handler.
- In tasks (`Tenma::async()`, `Tenma::all()`, `Tenma::detach()`).
- In timer and subscription callbacks.

The top level of the script runs outside any fiber. A function that waits throws a `LogicException` there. Wrap startup work that has to wait in `Tenma::async()`: it starts once the event loop runs.

In [classic mode](classic-mode.md) requests run without fibers, so the functions that wait throw there too.

See also: [Lifetime of a request](request-lifetime.md), [Fibers and asynchronous work](async.md).
