---
title: "Signal"
description: "A message to a durable function (Threading\\Durable) waiting for it, from anywhere: another request, another worker, another process."
order: 100
generated: true
---

`Threading\Signal` · final class · namespace [`Threading`](index.md)

```php
final class Signal
```

A message to a durable function (Threading\Durable) waiting for it, from anywhere: another
request, another worker, another process. A signal sent before anyone waits is kept for the next
receive() of its name.

## Methods

### send()

```php
public static function send(string $name, mixed $payload = null): void
```

Sends $payload (anything json_encode() takes) to the function waiting for $name, or keeps it.

**Parameters**

- `$name` `string`
- `$payload` `mixed`

### receive()

```php
public static function receive(string $name, float $timeout = 86400.0): mixed
```

Inside a durable function: waits until send() for $name, up to $timeout seconds, and returns the
payload, or null after the timeout. Reads like a blocking call; the process may stop and start
again meanwhile, the function goes on where it was.

**Parameters**

- `$name` `string`
- `$timeout` `float`

**Returns** `mixed`
