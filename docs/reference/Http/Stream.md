---
title: "Stream"
description: "Body of a streaming response."
order: 100
generated: true
---

`Http\Stream` · final class · namespace [`Http`](index.md)

```php
final class Stream
```

Body of a streaming response.

## Methods

### __construct()

```php
public function __construct(private readonly int $rid)
```

**Parameters**

- `$rid` `int`

### write()

```php
public function write(string $chunk): void
```

**Parameters**

- `$chunk` `string`
