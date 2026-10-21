---
title: "Client"
description: "Outgoing HTTP requests, without blocking the server: only the Fiber waits."
order: 100
generated: true
---

`Http\Client` · final class · namespace [`Http`](index.md)

```php
final class Client
```

Outgoing HTTP requests, without blocking the server: only the Fiber waits. Network errors and a
response that is not complete within $timeout seconds throw; HTTP error codes are not errors, they
are in the response's status.

## Properties

### $timeout

```php
public readonly float $timeout = 30.0;
```

## Methods

### __construct()

```php
public function __construct(public readonly float $timeout = 30.0)
```

**Parameters**

- `$timeout` `float`

### get()

```php
public function get(string $url, array $headers = []): \Http\ClientResponse
```

**Parameters**

- `$url` `string`
- `$headers` `array<string, string>`

**Returns** [`ClientResponse`](ClientResponse.md)

### post()

```php
public function post(string $url, string $body = '', array $headers = []): \Http\ClientResponse
```

**Parameters**

- `$url` `string`
- `$body` `string`
- `$headers` `array<string, string>`

**Returns** [`ClientResponse`](ClientResponse.md)

### send()

```php
public function send(string $method, string $url, array $headers = [], string $body = ''): \Http\ClientResponse
```

**Parameters**

- `$method` `string`
- `$url` `string`
- `$headers` `array<string, string>`
- `$body` `string`

**Returns** [`ClientResponse`](ClientResponse.md)
