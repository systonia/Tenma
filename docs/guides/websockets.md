---
title: WebSockets
description: Accept a WebSocket with Response::websocket(), receive and send messages, and broadcast to many connections.
order: 240
---

`Response::websocket()` turns a request into a WebSocket connection and runs a handler with it. The handler runs in the request's fiber for as long as the connection lasts. Waiting for the next message suspends only that fiber.

| Method or class                                                            | Does                                                |
| -------------------------------------------------------------------------- | --------------------------------------------------- |
| [`Response::websocket($handler)`](../reference/Http/Response.md#websocket) | accepts the connection and calls `$handler` with it |
| [`WebSocket`](../reference/Http/WebSocket.md)                              | `receive()`, `send($data)`, `close()`               |
| [`WebSocketResponse`](../reference/Http/WebSocketResponse.md)              | what `websocket()` returns                          |
| [`ConnectionClosed`](../reference/Http/ConnectionClosed.md)                | thrown by `send()` after the client has gone        |

## Echo

```php
<?php

use Http\{Request, Response, Server, WebSocket};

$echo = function (WebSocket $socket): void {
    $socket->send('welcome');

    while (($message = $socket->receive()) !== null) {
        $socket->send("echo: $message");
    }
    // receive() returned null: the client has closed the connection
};

new Server(8080, function (Request $request) use ($echo) {
    if ($request->path === '/echo') {
        return Response::websocket($echo);
    }

    return "connect a WebSocket to /echo\n";
})->start();
```

Try it in a browser console:

```js
ws = new WebSocket("ws://localhost:8080/echo");
ws.onmessage = (e) => console.log(e.data);
ws.send("hello");
```

- `receive()` waits for the next message and returns it as a string, text and binary alike. It returns null once the client has closed.
- `send($data)` sends a text message if `$data` is valid UTF-8, a binary one otherwise.
- `close()` closes the connection from the server side.
- The connection ends when the handler returns.

## Sending while receiving

`receive()` suspends the handler until a message arrives. To send on your own schedule at the same time, start a timer or a task in the handler. It ends with the connection.

```php
<?php

use Http\{Response, Server, WebSocket};
use Threading\Timer;

$handler = function (WebSocket $socket): void {
    $ping = fn () => $socket->send(json_encode(['type' => 'ping', 'at' => time()]));
    Timer::every(5.0, $ping);

    while (($message = $socket->receive()) !== null) {
        $socket->send(json_encode(['type' => 'got', 'text' => $message]));
    }
};

new Server(8080, fn () => Response::websocket($handler))->start();
```

## Broadcasting

To reach many connections, each subscribes to a [`Channel`](../reference/Messaging/Channel.md), and messages go out with `publish()`. The subscription belongs to the connection and is cancelled when it closes.

```php
<?php

use Http\{Request, Response, Server, WebSocket};
use Messaging\Channel;

$page = <<<'HTML'
<!doctype html>
<input id="text" autofocus>
<ul id="log"></ul>
<script>
  const ws = new WebSocket(`ws://${location.host}/chat`);
  ws.onmessage = (e) => {
    const item = document.createElement('li');
    item.textContent = e.data;
    document.getElementById('log').append(item);
  };
  document.getElementById('text').onkeydown = (e) => {
    if (e.key === 'Enter' && e.target.value) {
      ws.send(e.target.value);
      e.target.value = '';
    }
  };
</script>
HTML;

$channel = new Channel('chat');

$chat = function (WebSocket $socket) use ($channel): void {
    $name = 'user-' . bin2hex(random_bytes(2));
    $channel->subscribe(fn (string $line) => $socket->send($line));
    $channel->publish("$name joined");

    while (($message = $socket->receive()) !== null) {
        $channel->publish("$name: " . mb_substr($message, 0, 500));
    }

    $channel->publish("$name left");
};

new Server(8080, function (Request $request) use ($page, $chat) {
    if ($request->path === '/chat') {
        return Response::websocket($chat);
    }

    return Response::html($page);
})->start();
```

Open the page in two tabs. `publish()` reaches every subscription in every worker, so this works with `--workers 4` as well. With `--pubsub redis://…` it works across processes and machines too: see [messages between workers](database.md#messages-between-workers).

## Behaviour

- A request without `Upgrade: websocket` gets `426 Upgrade Required`. The same path cannot serve a page too, so use a separate path for the page.
- A WebSocket request that is no GET gets 405. One without `Sec-WebSocket-Version: 13` gets 426.
- `receive()` returns null once the client has closed.
- `send()` waits while the client is behind (more than 256 messages queued). It throws [`ConnectionClosed`](../reference/Http/ConnectionClosed.md) when the client is gone.
- A send that stalls longer than `--request-timeout` (default 30 s) disconnects the client. The waiting `send()` then throws `ConnectionClosed`.
- A message is at most `--max-body` bytes (default 10 MB).
- A client may have at most 256 messages, and at most `--max-body` bytes in all, that PHP has not read yet. Beyond that Tenma disconnects it.
- `ConnectionClosed` and `Cancelled` that leave the handler close the connection quietly. Any other exception goes to the log, and the client gets close code 1011.
- Timers, tasks and subscriptions the handler started end with the connection: see [Lifetime of a request](request-lifetime.md).
- Under `--watch`, open connections get one second to finish before a reload closes them.

See also: [`Response::websocket()`](../reference/Http/Response.md#websocket), [`WebSocket`](../reference/Http/WebSocket.md), [`WebSocketResponse`](../reference/Http/WebSocketResponse.md), [`Channel::subscribe()`](../reference/Messaging/Channel.md#subscribe).
