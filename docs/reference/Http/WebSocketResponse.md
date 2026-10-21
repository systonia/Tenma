---
title: "WebSocketResponse"
description: "Accepts the request as a WebSocket and runs the handler with the connection, from Response::websocket()."
order: 100
generated: true
---

`Http\WebSocketResponse` · final class · namespace [`Http`](index.md)

```php
final class WebSocketResponse
```

Accepts the request as a WebSocket and runs the handler with the connection, from Response::websocket().

## Properties

### $handler

```php
public readonly \Closure $handler;
```

## Methods

### __construct()

```php
public function __construct(public readonly \Closure $handler)
```

**Parameters**

- `$handler` [`Closure`](https://www.php.net/manual/en/class.closure.php)
