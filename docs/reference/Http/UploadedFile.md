---
title: "UploadedFile"
description: "An uploaded file, entirely in memory (up to --max-body)."
order: 100
generated: true
---

`Http\UploadedFile` · final class · namespace [`Http`](index.md)

```php
final class UploadedFile
```

An uploaded file, entirely in memory (up to --max-body).

## Properties

### $name

```php
public readonly string $name;
```

The file name from the client, without directory; never use it unchecked as a path.

### $type

```php
public readonly string $type;
```

The type the client states; not trustworthy.

## Methods

### __construct()

```php
public function __construct(
    public readonly string $name,
    public readonly string $type,
    private readonly string $contents,
)
```

**Parameters**

- `$name` `string`
- `$type` `string`
- `$contents` `string`

### size()

```php
public function size(): int
```

**Returns** `int`

### contents()

```php
public function contents(): string
```

**Returns** `string`

### moveTo()

```php
public function moveTo(string $path): void
```

Writes the file to $path without blocking the server.

**Parameters**

- `$path` `string`
