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
        $socket->send('welcome');
        while (($message = $socket->receive()) !== null) {
            $socket->send("echo: $message");
        }
        // receive() returned null: the client has closed the connection
    }),
    default => "connect a WebSocket to /echo\n",
});
```

In a browser console: `ws = new WebSocket('ws://localhost:8080/echo'); ws.onmessage = (e) => console.log(e.data); ws.send('hi')`.

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
    Tenma::setInterval(fn () => $socket->send(json_encode(['type' => 'ping', 'at' => time()])), 5.0);
    while (($message = $socket->receive()) !== null) {
        $socket->send(json_encode(['type' => 'got', 'text' => $message]));
    }
}));
```

## A chat room

Each connection subscribes to a channel and publishes what its client says. [`Tenma::publish()`](database.md#messages-between-workers) reaches every subscription in every worker, so the room works with `--workers 4` as well; with `--pubsub redis://…`, across processes and machines too.

```php
<?php

use Tenma\Http\{Request, Response, WebSocket};

$page = <<<'HTML'
<!doctype html>
<input id="text" placeholder="say something" autofocus>
<ul id="log"></ul>
<script>
  const ws = new WebSocket(`ws://${location.host}/room`);
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
    '/room' => Response::websocket(function (WebSocket $socket) use ($request) {
        $name = 'guest-' . substr(bin2hex(random_bytes(2)), 0, 4);
        Tenma::subscribe('room', fn (string $line) => $socket->send($line));
        Tenma::publish('room', "$name joined");
        while (($message = $socket->receive()) !== null) {
            Tenma::publish('room', "$name: " . mb_substr($message, 0, 500));
        }
        Tenma::publish('room', "$name left");
    }),
    default => new Response("Not found\n", 404),
});
```

The subscription belongs to the connection: when it closes, the subscription is cancelled with it.

## Limits

- A message is at most `--max-body` bytes (default 10 MB).
- A client may have at most 256 messages, and at most `--max-body` bytes in all, waiting that PHP has not read yet; beyond that Tenma disconnects it.
- A send that stalls longer than `--request-timeout` disconnects the client; the waiting `send()` throws `ConnectionClosed`.
- Under `--watch`, open connections get one second to finish before a reload closes them.

See also: [`Response::websocket()`](../reference/Tenma/Http/Response.md#websocket), [`WebSocket`](../reference/Tenma/Http/WebSocket.md), [`WebSocketResponse`](../reference/Tenma/Http/WebSocketResponse.md), [`Tenma::subscribe()`](../reference/Tenma.md#subscribe).
