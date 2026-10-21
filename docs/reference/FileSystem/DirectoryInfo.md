---
title: "DirectoryInfo"
description: "A directory by its path, without blocking the server: the work runs on background threads, only the Fiber waits."
order: 100
generated: true
---

`FileSystem\DirectoryInfo` · final class · namespace [`FileSystem`](index.md)

```php
final class DirectoryInfo
```

A directory by its path, without blocking the server: the work runs on background threads, only
the Fiber waits. Creating the object does no I/O; every method asks the file system then. Errors
throw RuntimeException with op, path and reason, errno as the code. Only in asynchronous code
(handlers, tasks, timers).

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

The name without the parent directory.

**Returns** `string`

### parent()

```php
public function parent(): \FileSystem\DirectoryInfo
```

**Returns** [`DirectoryInfo`](DirectoryInfo.md)

### exists()

```php
public function exists(): bool
```

Like file_exists(): a missing permission means false, not an exception.

**Returns** `bool`

### create()

```php
public function create(bool $recursive = false): void
```

Creates the directory and, with $recursive, the missing ones above it; an error if it already exists.

**Parameters**

- `$recursive` `bool`

### modified()

```php
public function modified(): float
```

The modification time as a Unix timestamp with fractions of a second.

**Returns** `float`

### files()

```php
public function files(string $pattern = '*'): array
```

The files in the directory, sorted by name, those matching $pattern (as fnmatch(): * and ?
within a name). Symbolic links count as what they point to.

**Parameters**

- `$pattern` `string`

**Returns** `list<self|`[`FileInfo`](FileInfo.md)`>`

### directories()

```php
public function directories(string $pattern = '*'): array
```

The directories in the directory, sorted by name, those matching $pattern.

**Parameters**

- `$pattern` `string`

**Returns** `list<self>`

### moveTo()

```php
public function moveTo(string $target): \FileSystem\DirectoryInfo
```

Moves the directory to $target, on the same file system, and returns it.

**Parameters**

- `$target` `string`

**Returns** [`DirectoryInfo`](DirectoryInfo.md)

### delete()

```php
public function delete(bool $recursive = false): void
```

Deletes an empty directory; with $recursive, also with its contents.

**Parameters**

- `$recursive` `bool`
