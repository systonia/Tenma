---
title: Streams and server-sent events
description: Send a response bit by bit with Response::stream(), or push events to the browser with Response::events().
order: 30
---

A streamed response sends its headers at once and every chunk right after it is written. The writer runs in the request's fiber: when a client cannot keep up, only that fiber waits, so a slow reader never fills the memory.

## Streaming a body

[`Response::stream()`](../reference/Tenma/Http/Response.md#stream) takes a writer that gets a [`Stream`](../reference/Tenma/Http/Stream.md):

```php
<?php

use Tenma\Http\{Request, Response, Stream};

Tenma::serve(8080, fn (Request $request) => match ($request->path) {
    '/export.csv' => Response::stream(function (Stream $out) {
        $out->write("id,name\n");
        foreach (range(1, 5) as $id) {
            Tenma::sleep(0.2);                       // stands for fetching the next page of rows
            $out->write("$id,customer $id\n");       // reaches the client right away
        }
    }, headers: [
        'content-type' => 'text/csv',
        'content-disposition' => 'attachment; filename="export.csv"',
    ]),
    default => "try /export.csv\n",
});
```

`curl -N localhost:8080/export.csv` shows the lines arriving one by one. The response ends when the writer returns. The default `Content-Type` is `application/octet-stream`; `$status` and `$headers` work as with `Response`.

Use streams for exports, logs, large generated files and anything where the first bytes should arrive before the last ones are computed.

## Server-sent events

[`Response::events()`](../reference/Tenma/Http/Response.md#events) sets up a `text/event-stream` and hands the writer an [`EventStream`](../reference/Tenma/Http/EventStream.md). The browser reads it with `EventSource`:

```php
<?php

use Tenma\Http\{EventStream, Request, Response};

$page = <<<'HTML'
<!doctype html>
<ul id="log"></ul>
<script>
  const source = new EventSource('/clock');
  source.addEventListener('tick', (event) => {
    const item = document.createElement('li');
    item.textContent = JSON.parse(event.data).time;
    document.getElementById('log').prepend(item);
  });
</script>
HTML;

Tenma::serve(8080, fn (Request $request) => match ($request->path) {
    '/' => Response::html($page),
    '/clock' => Response::events(function (EventStream $events) {
        $id = 0;
        while (true) {
            $events->send(json_encode(['time' => date('H:i:s')]), event: 'tick', id: (string) ++$id);
            Tenma::sleep(1);
        }
    }),
    default => new Response("Not found\n", 404),
});
```

- `send($data, event: …, id: …)` writes one event. Without `event` the browser delivers it to `onmessage`; the `id` comes back as `Last-Event-ID` when the browser reconnects.
- `comment($text)` writes a comment line the browser ignores. Send one every 15–30 seconds on a quiet stream so proxies do not close the connection.

The loop above never returns by itself. That is fine: when the browser goes away, the next `send()` throws.

## When the client leaves

A write to a connection the client has closed throws [`ConnectionClosed`](../reference/Tenma/Http/ConnectionClosed.md). Let it end the writer, or catch it to clean up:

```php
<?php

use Tenma\Http\{ConnectionClosed, EventStream, Response};

$listeners = 0;

Tenma::serve(8080, function () use (&$listeners) {
    return Response::events(function (EventStream $events) use (&$listeners) {
        $listeners++;
        try {
            while (true) {
                $events->send("listeners: $listeners");
                Tenma::sleep(2);
            }
        } catch (ConnectionClosed) {
            // the browser tab is closed; nothing to send to anymore
        } finally {
            $listeners--;
        }
    });
});
```

If the client disconnects while the writer waits for something else (a sleep, a query), it gets [`Tenma\Cancelled`](async.md#cancellation) there instead. Timers, tasks and subscriptions the writer started end with the connection.

A client that stops reading is disconnected once a write has stalled for longer than `--request-timeout` (default 30 s); the waiting writer then gets `ConnectionClosed`.

## Events for many clients

To push the same event to every open stream, subscribe each stream to a channel and publish to it. The subscription ends with the stream:

```php
<?php

use Tenma\Http\{EventStream, Request, Response};

Tenma::serve(8080, fn (Request $request) => match ($request->path) {
    '/events' => Response::events(function (EventStream $events) {
        Tenma::subscribe('news', fn (string $message) => $events->send($message, event: 'news'));
        while (true) {
            $events->comment('keep-alive');   // also notices when the client is gone
            Tenma::sleep(15);
        }
    }),
    '/publish' => (function () use ($request) {
        Tenma::publish('news', $request->query['text'] ?? 'hello');
        return "sent\n";
    })(),
    default => new Response("Not found\n", 404),
});
```

`curl -N localhost:8080/events` in one terminal and `curl 'localhost:8080/publish?text=hi'` in another. With several workers or processes, see [messages between workers](database.md#messages-between-workers).

See also: [`Response`](../reference/Tenma/Http/Response.md), [`Stream`](../reference/Tenma/Http/Stream.md), [`EventStream`](../reference/Tenma/Http/EventStream.md), [`StreamedResponse`](../reference/Tenma/Http/StreamedResponse.md), [`ConnectionClosed`](../reference/Tenma/Http/ConnectionClosed.md).
