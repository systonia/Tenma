---
title: "WebSocket"
description: "An accepted WebSocket connection, passed to the handler of Response::websocket()."
order: 100
generated: true
---

`Tenma\Http\WebSocket` · final class · namespace [`Tenma\Http`](index.md)

```php
final class WebSocket
```

An accepted WebSocket connection, passed to the handler of Response::websocket().

## Methods

### __construct()

```php
public function __construct(private readonly int $rid)
```

**Parameters**

- `$rid` `int`

### receive()

```php
public function receive(): ?string
```

Waits for the next message without blocking the server; null when the client has closed. Text and binary
messages both arrive as a string of their bytes.

**Returns** `?string`

### send()

```php
public function send(string $data): void
```

Sends $data as a text message if it is valid UTF-8, otherwise as a binary one. Waits while the client
is behind, like Stream::write().

**Parameters**

- `$data` `string`

### close()

```php
public function close(): void
```
