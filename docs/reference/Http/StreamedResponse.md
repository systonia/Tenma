---
title: "StreamedResponse"
description: "A response whose body a writer produces while it is sent, from Response::stream() and Response::events()."
order: 100
generated: true
---

`Http\StreamedResponse` · final class · namespace [`Http`](index.md)

```php
final class StreamedResponse
```

A response whose body a writer produces while it is sent, from Response::stream() and Response::events().

## Properties

### $writer

```php
public readonly \Closure $writer;
```

### $status

```php
public readonly int $status;
```

### $headers

```php
public readonly array $headers;
```

**Type** `array<string, string|list<string>>`

several values as a list, for example for Set-Cookie

## Methods

### __construct()

```php
public function __construct(
    public readonly \Closure $writer,
    public readonly int $status,
    public readonly array $headers,
)
```

**Parameters**

- `$writer` [`Closure`](https://www.php.net/manual/en/class.closure.php)
- `$status` `int`
- `$headers` `array<string, string|list<string>>`: several values as a list, for example for Set-Cookie
