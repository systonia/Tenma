---
title: WebSockets
description: Accept a WebSocket with Response::websocket(), receive and send messages, and broadcast to many connections.
order: 40
---

[`Response::websocket()`](../reference/Tenma/Http/Response.md#websocket) turns a request into a WebSocket connection and runs its handler with a [`WebSocket`](../reference/Tenma/Http/WebSocket.md). The handler runs in the request's fiber for as long as the connection lasts; waiting for the next message suspends only that fiber.

## Echo

```php
<?php

use Tenma\Http\{Request, Response, WebSocket};

Tenma::serve(8080, fn (Request $request) => match ($request->path) {
    '/echo' => Response::websocket(function (WebSocket $socket) {
        $socket->send('Welcome to Sonnenkorn');
        while (($message = $socket->receive()) !== null) {
            $socket->send("echo: $message");
        }
        // receive() returned null: the client has closed the connection
    }),
    default => "connect a WebSocket to /echo\n",
});
```

In a browser console: `ws = new WebSocket('ws://localhost:8080/echo'); ws.onmessage = (e) => console.log(e.data); ws.send('two croissants')`.

- `receive()` waits for the next message and returns it as a string, text and binary messages alike. It returns `null` once the client has closed.
- `send($data)` sends a text message if `$data` is valid UTF-8, a binary one otherwise. It waits while the client is behind, like a stream write, and throws [`ConnectionClosed`](../reference/Tenma/Http/ConnectionClosed.md) when the client is gone.
- `close()` closes the connection from the server side.
- The connection ends when the handler returns.

A request that is no WebSocket upgrade gets `426 Upgrade Required`, so the same path cannot serve a page too; use a separate path for the page.

## Sending while receiving

`receive()` blocks the handler's fiber until a message arrives. To send on your own schedule at the same time, run the sender as a task or timer of the request: it ends with the connection.

```php
<?php

use Tenma\Http\{Request, Response, WebSocket};

Tenma::serve(8080, fn (Request $request) => Response::websocket(function (WebSocket $socket) {
    // The oven's state every five seconds, whatever the client sends meanwhile
    Tenma::setInterval(fn () => $socket->send(json_encode(['type' => 'oven', 'celsius' => random_int(215, 225), 'at' => time()])), 5.0);
    while (($message = $socket->receive()) !== null) {
        $socket->send(json_encode(['type' => 'noted', 'text' => $message]));
    }
}));
```

## An order board for the staff

Every screen in the bakery opens a connection; each subscribes to a channel and publishes what its member of staff does with an order. [`Tenma::publish()`](database.md#messages-between-workers) reaches every subscription in every worker, so the board works with `--workers 4` as well; with `--pubsub redis://…`, across processes and machines too.

```php
<?php

use Tenma\Http\{Request, Response, WebSocket};

$page = <<<'HTML'
<!doctype html>
<input id="text" placeholder="a1f00 is in the oven" autofocus>
<ul id="log"></ul>
<script>
  const ws = new WebSocket(`ws://${location.host}/board`);
  ws.onmessage = (e) => {
    const item = document.createElement('li');
    item.textContent = e.data;
    document.getElementById('log').append(item);
  };
  document.getElementById('text').onkeydown = (e) => {
    if (e.key === 'Enter' && e.target.value) { ws.send(e.target.value); e.target.value = ''; }
  };
</script>
HTML;

Tenma::serve(8080, fn (Request $request) => match ($request->path) {
    '/' => Response::html($page),
    '/board' => Response::websocket(function (WebSocket $socket) use ($request) {
        $screen = 'screen-' . substr(bin2hex(random_bytes(2)), 0, 4);
        Tenma::subscribe('board', fn (string $line) => $socket->send($line));
        Tenma::publish('board', "$screen is on");
        while (($message = $socket->receive()) !== null) {
            Tenma::publish('board', "$screen: " . mb_substr($message, 0, 500));
        }
        Tenma::publish('board', "$screen is off");
    }),
    default => new Response("Not found\n", 404),
});
```

The subscription belongs to the connection: when it closes, the subscription is cancelled with it.

The bakery's real board and the customer's order page follow each other the same way. They are live components of Waypoint Live over one WebSocket per tab; after a click on the board, `Broadcast::changed()` goes out through `Tenma::publish()`, and every worker renders its open tabs again, so the customer's page moves on whichever worker serves it (`src/Components/OrderBoard.php` and `OrderTracker.php`).

## Limits

- A message is at most `--max-body` bytes (default 10 MB).
- A client may have at most 256 messages, and at most `--max-body` bytes in all, waiting that PHP has not read yet; beyond that Tenma disconnects it.
- A send that stalls longer than `--request-timeout` disconnects the client; the waiting `send()` throws `ConnectionClosed`.
- Under `--watch`, open connections get one second to finish before a reload closes them.

See also: [`Response::websocket()`](../reference/Tenma/Http/Response.md#websocket), [`WebSocket`](../reference/Tenma/Http/WebSocket.md), [`WebSocketResponse`](../reference/Tenma/Http/WebSocketResponse.md), [`Tenma::subscribe()`](../reference/Tenma.md#subscribe).
