---
title: "EventStream"
description: "Server-Sent Events on a stream."
order: 100
generated: true
---

`Http\EventStream` · final class · namespace [`Http`](index.md)

```php
final class EventStream
```

Server-Sent Events on a stream.

## Methods

### __construct()

```php
public function __construct(private readonly \Http\Stream $stream)
```

**Parameters**

- `$stream` [`Stream`](Stream.md)

### send()

```php
public function send(string $data, ?string $event = null, ?string $id = null): void
```

**Parameters**

- `$data` `string`
- `$event` `?string`
- `$id` `?string`

### comment()

```php
public function comment(string $text = ''): void
```

A comment keeps the connection alive through proxies; the browser ignores it.

**Parameters**

- `$text` `string`
