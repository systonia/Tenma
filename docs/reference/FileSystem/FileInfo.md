---
title: "FileInfo"
description: "A file by its path, without blocking the server: the work runs on background threads, only the Fiber waits."
order: 100
generated: true
---

`FileSystem\FileInfo` · final class · namespace [`FileSystem`](index.md)

```php
final class FileInfo
```

A file by its path, without blocking the server: the work runs on background threads, only the
Fiber waits. Creating the object does no I/O; every method asks the file system then. Paths as
with PHP's file functions (relative to chdir(), open_basedir applies). Errors (missing, no
permission) throw RuntimeException with op, path and reason, errno as the code; exists() is the
way to ask for a file that may be missing. A running file op cannot be cancelled; it runs to
completion. Only in asynchronous code (handlers, tasks, timers); file_get_contents() & co. block
the worker.

## Properties

### $path

```php
public readonly string $path;
```

## Methods

### __construct()

```php
public function __construct(public readonly string $path)
```

**Parameters**

- `$path` `string`

### name()

```php
public function name(): string
```

The name without the directory.

**Returns** `string`

### directory()

```php
public function directory(): \FileSystem\DirectoryInfo
```

**Returns** [`DirectoryInfo`](DirectoryInfo.md)

### exists()

```php
public function exists(): bool
```

Like file_exists(): a missing permission means false, not an exception.

**Returns** `bool`

### read()

```php
public function read(): string
```

**Returns** `string`

### write()

```php
public function write(string $data): void
```

Writes $data, replacing the file; the directory must exist.

**Parameters**

- `$data` `string`

### append()

```php
public function append(string $data): void
```

Appends $data in one write, creating the file if it is missing.

**Parameters**

- `$data` `string`

### size()

```php
public function size(): int
```

**Returns** `int`

### modified()

```php
public function modified(): float
```

The modification time as a Unix timestamp with fractions of a second.

**Returns** `float`

### copyTo()

```php
public function copyTo(string $target): \FileSystem\FileInfo
```

Copies the file to $target and returns it; copying a file onto itself is an error.

**Parameters**

- `$target` `string`

**Returns** [`FileInfo`](FileInfo.md)

### moveTo()

```php
public function moveTo(string $target): \FileSystem\FileInfo
```

Moves the file to $target, also across file system boundaries, and returns it.

**Parameters**

- `$target` `string`

**Returns** [`FileInfo`](FileInfo.md)

### delete()

```php
public function delete(): void
```
