---
title: "FetchResponse"
description: "Response from fetch()."
order: 100
generated: true
---

`Tenma\Http\FetchResponse` · final class · namespace [`Tenma\Http`](index.md)

```php
final class FetchResponse
```

Response from fetch().

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
- `$headers` `list<string>` — header lines "Name: Value"
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
