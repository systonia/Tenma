---
title: "ClientResponse"
description: "The response of an Http\\Client request: status, header lines and body."
order: 100
generated: true
---

`Http\ClientResponse` · final class · namespace [`Http`](index.md)

```php
final class ClientResponse
```

The response of an Http\Client request: status, header lines and body.

## Properties

### $status

```php
public readonly int $status;
```

### $headers

```php
public readonly array $headers;
```

**Type** `list<string>`

header lines "Name: Value"

### $body

```php
public readonly string $body;
```

## Methods

### __construct()

```php
public function __construct(
    public readonly int $status,
    public readonly array $headers,
    public readonly string $body,
)
```

**Parameters**

- `$status` `int`
- `$headers` `list<string>`: header lines "Name: Value"
- `$body` `string`

### header()

```php
public function header(string $name): ?string
```

**Parameters**

- `$name` `string`

**Returns** `?string`

### json()

```php
public function json(): mixed
```

**Returns** `mixed`
