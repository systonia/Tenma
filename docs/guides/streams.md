---
title: Streams and server-sent events
description: Send a response bit by bit with Response::stream(), or push events to the browser with Response::events().
order: 230
---

A streamed response sends its headers at once and every chunk right after it is written. The writer runs in the request's fiber. When a client cannot keep up, only that fiber waits, so a slow reader never fills the memory.

| Method or class                                                                        | Does                                                     |
| -------------------------------------------------------------------------------------- | -------------------------------------------------------- |
| [`Response::stream($writer, $status, $headers)`](../reference/Http/Response.md#stream) | a response whose body `$writer` produces with a `Stream` |
| [`Response::events($writer)`](../reference/Http/Response.md#events)                    | a `text/event-stream` whose writer gets an `EventStream` |
| [`Stream`](../reference/Http/Stream.md)                                                | `write($chunk)`                                          |
| [`EventStream`](../reference/Http/EventStream.md)                                      | `send($data, event:, id:)`, `comment($text)`             |
| [`StreamedResponse`](../reference/Http/StreamedResponse.md)                            | what `stream()` and `events()` return                    |
| [`ConnectionClosed`](../reference/Http/ConnectionClosed.md)                            | thrown by a write after the client has gone              |

## Streaming a body

```php
<?php

use Http\{Response, Server, Stream};
use Threading\Task;

$headers = [
    'content-type' => 'text/csv',
    'content-disposition' => 'attachment; filename="users.csv"',
];

new Server(8080, fn () => Response::stream(function (Stream $out) {
    $out->write("id,name\n");

    foreach (['ada', 'bob', 'eve'] as $i => $name) {
        Task::delay(0.2);                // stands for fetching the next page of rows
        $out->write("$i,$name\n");       // reaches the client right away
    }
}, headers: $headers))->start();
```

`curl -N localhost:8080` shows the lines arriving one by one. The response ends when the writer returns.

- The default `Content-Type` is `application/octet-stream`.
- `$status` and `$headers` work as with `Response`.
- Use streams for exports, logs, large generated files: anything where the first bytes should arrive before the last ones are computed.

## Server-sent events

`Response::events()` sets `Content-Type: text/event-stream` and `Cache-Control: no-cache`. The writer gets an `EventStream`. The browser reads it with `EventSource`.

```php
<?php

use Http\{EventStream, Request, Response, Server};
use Threading\Task;

$page = <<<'HTML'
<!doctype html>
<ul id="log"></ul>
<script>
  const source = new EventSource('/events');
  source.addEventListener('tick', (event) => {
    const item = document.createElement('li');
    item.textContent = event.data;
    document.getElementById('log').prepend(item);
  });
</script>
HTML;

$ticks = function (EventStream $events): void {
    for ($id = 1; ; $id++) {
        $events->send(date('H:i:s'), event: 'tick', id: (string) $id);
        Task::delay(1);
    }
};

new Server(8080, function (Request $request) use ($page, $ticks) {
    if ($request->path === '/events') {
        return Response::events($ticks);
    }

    return Response::html($page);
})->start();
```

- `send($data, event: …, id: …)` writes one event. A line break in `$data` becomes several `data:` lines.
- Without `event`, the browser delivers the event to `onmessage`.
- The `id` comes back as `Last-Event-ID` when the browser reconnects.
- `comment($text)` writes a comment line the browser ignores. Send one every 15 to 30 seconds on a quiet stream, so proxies do not close the connection.

The writer above never returns by itself. That is fine: when the browser goes away, the next `send()` throws and ends it.

## When the client leaves

A write to a connection the client has closed throws [`ConnectionClosed`](../reference/Http/ConnectionClosed.md). Let it end the writer, or catch it to clean up.

```php
<?php

use Http\{ConnectionClosed, EventStream, Response, Server};
use Threading\Task;

$open = 0;

$count = function (EventStream $events) use (&$open): void {
    $open++;

    try {
        while (true) {
            $events->send("$open clients connected");
            Task::delay(2);
        }
    } catch (ConnectionClosed) {
        // the client is gone; nothing to send to anymore
    } finally {
        $open--;
    }
};

new Server(8080, fn () => Response::events($count))->start();
```

If the client disconnects while the writer waits for something else (a sleep, a query), the writer gets [`Threading\Cancelled`](../reference/Threading/Cancelled.md) there instead. The `finally` block runs in both cases.

## Events for many clients

To push the same event to every open stream, subscribe each stream to a [`Channel`](../reference/Messaging/Channel.md) and publish to it. The subscription ends with the stream.

```php
<?php

use Http\{EventStream, Request, Response, Server};
use Messaging\Channel;
use Threading\Task;

$news = new Channel('news');

$listen = function (EventStream $events) use ($news): void {
    $news->subscribe(fn (string $text) => $events->send($text, event: 'news'));

    while (true) {
        $events->comment('keep-alive');   // also notices when the client is gone
        Task::delay(15);
    }
};

new Server(8080, function (Request $request) use ($listen, $news) {
    if ($request->path === '/listen') {
        return Response::events($listen);
    }

    $news->publish($request->query['text'] ?? 'hello');

    return "published\n";
})->start();
```

Run `curl -N localhost:8080/listen` in one terminal and `curl 'localhost:8080/?text=hi'` in another. With several workers or processes, see [messages between workers](database.md#messages-between-workers).

## Behaviour

- The headers go out when the writer starts. Each `write()` goes out right after it.
- Up to 16 chunks wait for a slow client. Then `write()` suspends the fiber until the client catches up.
- `write()`, `send()` and `comment()` throw `ConnectionClosed` once the client has gone.
- A client that stops reading is disconnected once a write has stalled longer than `--request-timeout` (default 30 s). The waiting write then throws `ConnectionClosed`.
- `ConnectionClosed` and `Cancelled` that leave the writer end the stream quietly. Any other exception goes to the log.
- Only a writer that returns ends the body normally. After an exception, the client sees the response cut off.
- `send()` throws an `InvalidArgumentException` when `event` or `id` contains a line break.
- Timers, tasks and subscriptions the writer started end with the connection: see [Lifetime of a request](request-lifetime.md).

See also: [`Response`](../reference/Http/Response.md), [`Stream`](../reference/Http/Stream.md), [`EventStream`](../reference/Http/EventStream.md), [`StreamedResponse`](../reference/Http/StreamedResponse.md), [`ConnectionClosed`](../reference/Http/ConnectionClosed.md), [`Channel::subscribe()`](../reference/Messaging/Channel.md#subscribe).
