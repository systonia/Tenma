---
title: Event loop and fibers
description: How one worker serves many requests at once, what waits without blocking, and what blocks the worker.
order: 110
---

Each worker runs one event loop. Every request, task, timer callback and subscription callback runs in its own [fiber](https://www.php.net/manual/en/language.fibers.php). A fiber that waits is suspended, and the event loop runs the other fibers meanwhile.

## How waiting works

1. A handler calls a method that waits, such as `Threading\Task::delay()`.
2. Tenma starts an op for it outside PHP, and the fiber suspends.
3. The event loop runs other requests meanwhile.
4. When the op is done, the event loop resumes the fiber with the result.

There is no `await` keyword and no promise type. A method that waits simply returns its result:

```php
<?php

use Http\Client;
use Threading\Task;

new Http\Server(8080, function () {
    Task::delay(1);   // only this request waits

    $response = new Client()->get('https://httpbin.org/get');

    return "httpbin answered {$response->status}\n";
})->start();
```

## Waiting and blocking

Code waits when it calls a Tenma method that waits. Other requests of the worker keep running.

PHP's own functions that wait for the network (`fsockopen()`, `file_get_contents()` on a URL, PDO with MySQL or Postgres, mysqli) wait the same way: see [Plain PHP under tenma](compatibility.md). Code blocks when it calls one of the functions that cannot, such as `sleep()`, `file_get_contents()` on a file or curl. They work, but no other request of that worker runs until they return.

```php
<?php

use Http\Request;
use Threading\Task;

new Http\Server(8080, function (Request $request) {
    if ($request->path === '/blocking') {
        sleep(1);   // holds up the whole worker
    } else {
        Task::delay(1);   // suspends this fiber only
    }

    return "done\n";
})->start();
```

Five parallel calls to `/` finish after about one second. Five parallel calls to `/blocking` take about five seconds with one worker.

A fiber also gives way only when it waits. A long loop that computes holds up its worker, and an endless loop holds it up for good.

## Calls that wait without blocking

| Call                                                                                                      | Page                                      |
| --------------------------------------------------------------------------------------------------------- | ----------------------------------------- |
| `UploadedFile::moveTo()`                                                                                  | [Serving HTTP](http.md)                   |
| `Client::get()`, `post()`, `send()`                                                                       | [Outgoing HTTP](fetch.md)                 |
| `Task::delay()`, `Task::all()`, `$task->await()`                                                          | [Fibers and asynchronous work](async.md)  |
| `Stream::write()`, `EventStream::send()`, `EventStream::comment()`                                        | [Streams](streams.md)                     |
| `WebSocket::receive()`, `WebSocket::send()`                                                               | [WebSockets](websockets.md)               |
| `FileInfo::read()`, `write()` and every other `FileInfo` and `DirectoryInfo` method that touches the disk | [Files](files.md)                         |
| `Postgres::query()`, `first()`, `execute()`, `script()`, `transaction()`                                  | [Databases and messages](database.md)     |
| `Redis::command()`, `get()`, `set()`                                                                      | [Databases and messages](database.md)     |
| PHP's socket streams, `http://` and `https://`, `stream_select()`, PDO MySQL, PDO Postgres, mysqli        | [Plain PHP under tenma](compatibility.md) |

## Where code can wait

- In an `Http\Server` handler.
- In tasks (`Threading\Task::run()`, `Task::all()`, `Task::detach()`).
- In timer and subscription callbacks.

The top level of the script runs outside any fiber. A method that waits throws a `LogicException` there. Wrap startup work that has to wait in `Threading\Task::run()`: it starts once the event loop runs.

In [classic mode](classic-mode.md) requests run without fibers, so the methods that wait throw there too.

See also: [Lifetime of a request](request-lifetime.md), [Fibers and asynchronous work](async.md).
