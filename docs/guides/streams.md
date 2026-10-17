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
    '/orders.csv' => Response::stream(function (Stream $out) {
        $out->write("code,pickup,customer\n");
        foreach (['Anna', 'Ben', 'Clara', 'David', 'Emma'] as $i => $customer) {
            Tenma::sleep(0.2);                                      // stands for fetching the next page of orders
            $out->write(sprintf("%05x,2026-10-%02d,%s\n", 0xa1f00 + $i, 19 + $i, $customer));   // reaches the client right away
        }
    }, headers: [
        'content-type' => 'text/csv',
        'content-disposition' => 'attachment; filename="orders.csv"',
    ]),
    default => "try /orders.csv\n",
});
```

`curl -N localhost:8080/orders.csv` shows the lines arriving one by one. The response ends when the writer returns. The default `Content-Type` is `application/octet-stream`; `$status` and `$headers` work as with `Response`.

Use streams for exports, logs, large generated files and anything where the first bytes should arrive before the last ones are computed.

## Server-sent events

[`Response::events()`](../reference/Tenma/Http/Response.md#events) sets up a `text/event-stream` and hands the writer an [`EventStream`](../reference/Tenma/Http/EventStream.md). The browser reads it with `EventSource`:

```php
<?php

use Tenma\Http\{EventStream, Request, Response};

$page = <<<'HTML'
<!doctype html>
<p>Croissants left today:</p>
<ul id="log"></ul>
<script>
  const source = new EventSource('/stock');
  source.addEventListener('stock', (event) => {
    const item = document.createElement('li');
    const { time, croissants } = JSON.parse(event.data);
    item.textContent = `${time}: ${croissants}`;
    document.getElementById('log').prepend(item);
  });
</script>
HTML;

$croissants = 24;
Tenma::setInterval(function () use (&$croissants) {
    $croissants = max(0, $croissants - random_int(0, 2));   // stands for the orders coming in
}, 1.0);

// A closure with use (&$croissants): an arrow function (fn) would capture a copy
Tenma::serve(8080, function (Request $request) use ($page, &$croissants) {
    return match ($request->path) {
        '/' => Response::html($page),
        '/stock' => Response::events(function (EventStream $events) use (&$croissants) {
            $id = 0;
            while (true) {
                $events->send(json_encode(['time' => date('H:i:s'), 'croissants' => $croissants]), event: 'stock', id: (string) ++$id);
                Tenma::sleep(1);
            }
        }),
        default => new Response("Not found\n", 404),
    };
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

$watching = 0;

Tenma::serve(8080, function () use (&$watching) {
    return Response::events(function (EventStream $events) use (&$watching) {
        $watching++;
        try {
            while (true) {
                $events->send("$watching customers are watching the oven");
                Tenma::sleep(2);
            }
        } catch (ConnectionClosed) {
            // the browser tab is closed; nothing to send to anymore
        } finally {
            $watching--;
        }
    });
});
```

If the client disconnects while the writer waits for something else (a sleep, a query), it gets [`Tenma\Cancelled`](async.md#cancellation) there instead. Timers, tasks and subscriptions the writer started end with the connection.

A client that stops reading is disconnected once a write has stalled for longer than `--request-timeout` (default 30 s); the waiting writer then gets `ConnectionClosed`.

## Events for many clients

To push the same event to every open stream, subscribe each stream to a channel and publish to it. Here every screen in the bakery hears of a new order; the subscription ends with the stream:

```php
<?php

use Tenma\Http\{EventStream, Request, Response};

Tenma::serve(8080, fn (Request $request) => match ($request->path) {
    '/board' => Response::events(function (EventStream $events) {
        Tenma::subscribe('orders', fn (string $code) => $events->send($code, event: 'order'));
        while (true) {
            $events->comment('keep-alive');   // also notices when the client is gone
            Tenma::sleep(15);
        }
    }),
    '/orders' => (function () use ($request) {
        Tenma::publish('orders', $request->query['code'] ?? bin2hex(random_bytes(5)));
        return "order placed\n";
    })(),
    default => new Response("Not found\n", 404),
});
```

`curl -N localhost:8080/board` in one terminal and `curl 'localhost:8080/orders?code=a1f00'` in another. With several workers or processes, see [messages between workers](database.md#messages-between-workers).

See also: [`Response`](../reference/Tenma/Http/Response.md), [`Stream`](../reference/Tenma/Http/Stream.md), [`EventStream`](../reference/Tenma/Http/EventStream.md), [`StreamedResponse`](../reference/Tenma/Http/StreamedResponse.md), [`ConnectionClosed`](../reference/Tenma/Http/ConnectionClosed.md).
