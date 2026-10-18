---
title: Streams and server-sent events
description: Send a response bit by bit with Response::stream(), or push events to the browser with Response::events().
order: 230
---

A streamed response sends its headers at once and every chunk right after it is written. The writer runs in the request's fiber. When a client cannot keep up, only that fiber waits, so a slow reader never fills the memory.

| Method or class                                                                              | Does                                                     |
| -------------------------------------------------------------------------------------------- | -------------------------------------------------------- |
| [`Response::stream($writer, $status, $headers)`](../reference/Tenma/Http/Response.md#stream) | a response whose body `$writer` produces with a `Stream` |
| [`Response::events($writer)`](../reference/Tenma/Http/Response.md#events)                    | a `text/event-stream` whose writer gets an `EventStream` |
| [`Stream`](../reference/Tenma/Http/Stream.md)                                                | `write($chunk)`                                          |
| [`EventStream`](../reference/Tenma/Http/EventStream.md)                                      | `send($data, event:, id:)`, `comment($text)`             |
| [`StreamedResponse`](../reference/Tenma/Http/StreamedResponse.md)                            | what `stream()` and `events()` return                    |
| [`ConnectionClosed`](../reference/Tenma/Http/ConnectionClosed.md)                            | thrown by a write after the client has gone              |

## Streaming a body

```php
<?php

use Tenma\Http\{Response, Stream};

$headers = [
    'content-type' => 'text/csv',
    'content-disposition' => 'attachment; filename="users.csv"',
];

Tenma::serve(8080, fn () => Response::stream(function (Stream $out) {
    $out->write("id,name\n");

    foreach (['ada', 'bob', 'eve'] as $i => $name) {
        Tenma::sleep(0.2);               // stands for fetching the next page of rows
        $out->write("$i,$name\n");       // reaches the client right away
    }
}, headers: $headers));
```

`curl -N localhost:8080` shows the lines arriving one by one. The response ends when the writer returns.

- The default `Content-Type` is `application/octet-stream`.
- `$status` and `$headers` work as with `Response`.
- Use streams for exports, logs, large generated files: anything where the first bytes should arrive before the last ones are computed.

## Server-sent events

`Response::events()` sets `Content-Type: text/event-stream` and `Cache-Control: no-cache`. The writer gets an `EventStream`. The browser reads it with `EventSource`.

```php
<?php

use Tenma\Http\{EventStream, Request, Response};

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
        Tenma::sleep(1);
    }
};

Tenma::serve(8080, function (Request $request) use ($page, $ticks) {
    if ($request->path === '/events') {
        return Response::events($ticks);
    }

    return Response::html($page);
});
```

- `send($data, event: …, id: …)` writes one event. A line break in `$data` becomes several `data:` lines.
- Without `event`, the browser delivers the event to `onmessage`.
- The `id` comes back as `Last-Event-ID` when the browser reconnects.
- `comment($text)` writes a comment line the browser ignores. Send one every 15 to 30 seconds on a quiet stream, so proxies do not close the connection.

The writer above never returns by itself. That is fine: when the browser goes away, the next `send()` throws and ends it.

## When the client leaves

A write to a connection the client has closed throws [`ConnectionClosed`](../reference/Tenma/Http/ConnectionClosed.md). Let it end the writer, or catch it to clean up.

```php
<?php

use Tenma\Http\{ConnectionClosed, EventStream, Response};

$open = 0;

$count = function (EventStream $events) use (&$open): void {
    $open++;

    try {
        while (true) {
            $events->send("$open clients connected");
            Tenma::sleep(2);
        }
    } catch (ConnectionClosed) {
        // the client is gone; nothing to send to anymore
    } finally {
        $open--;
    }
};

Tenma::serve(8080, fn () => Response::events($count));
```

If the client disconnects while the writer waits for something else (a sleep, a query), the writer gets [`Tenma\Cancelled`](../reference/Tenma/Cancelled.md) there instead. The `finally` block runs in both cases.

## Events for many clients

To push the same event to every open stream, subscribe each stream to a channel and publish to it. The subscription ends with the stream.

```php
<?php

use Tenma\Http\{EventStream, Request, Response};

$listen = function (EventStream $events): void {
    Tenma::subscribe('news', fn (string $text) => $events->send($text, event: 'news'));

    while (true) {
        $events->comment('keep-alive');   // also notices when the client is gone
        Tenma::sleep(15);
    }
};

Tenma::serve(8080, function (Request $request) use ($listen) {
    if ($request->path === '/listen') {
        return Response::events($listen);
    }

    Tenma::publish('news', $request->query['text'] ?? 'hello');

    return "published\n";
});
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

See also: [`Response`](../reference/Tenma/Http/Response.md), [`Stream`](../reference/Tenma/Http/Stream.md), [`EventStream`](../reference/Tenma/Http/EventStream.md), [`StreamedResponse`](../reference/Tenma/Http/StreamedResponse.md), [`ConnectionClosed`](../reference/Tenma/Http/ConnectionClosed.md), [`Tenma::subscribe()`](../reference/Tenma.md#subscribe).
