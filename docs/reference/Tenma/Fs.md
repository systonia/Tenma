---
title: "Fs"
description: "Asynchronous file system functions: only the Fiber waits while the work runs on a background thread."
order: 100
generated: true
---

`Tenma\Fs` · final class · namespace [`Tenma`](index.md)

```php
final class Fs
```

Asynchronous file system functions: only the Fiber waits while the work runs on a background thread.

## Methods

### readFile()

```php
public static function readFile(string $path): string
```

Files and directories, without blocking the server: the work runs on background threads,
only the Fiber waits. Paths as with PHP's file functions (relative to chdir(), open_basedir applies).
Errors (missing, no permission) throw RuntimeException with op, path and reason, errno as code.
A running file op cannot be cancelled; it runs to completion.
Only in asynchronous code (handlers, tasks, timers); file_get_contents & co. block the worker.

**Parameters**

- `$path` `string`

**Returns** `string`

### writeFile()

```php
public static function writeFile(string $path, string $data): void
```

**Parameters**

- `$path` `string`
- `$data` `string`

### appendFile()

```php
public static function appendFile(string $path, string $data): void
```

**Parameters**

- `$path` `string`
- `$data` `string`

### exists()

```php
public static function exists(string $path): bool
```

**Parameters**

- `$path` `string`

**Returns** `bool`

### stat()

```php
public static function stat(string $path): array
```

**Parameters**

- `$path` `string`

**Returns** `array{size: int, modified: float, isFile: bool, isDir: bool}`

### readDir()

```php
public static function readDir(string $path): array
```

**Parameters**

- `$path` `string`

**Returns** `list<string>`: Names in the directory, sorted, without . and ..

### mkdir()

```php
public static function mkdir(string $path, bool $recursive = false): void
```

Like mkdir() in PHP: without $recursive, an error if the directory already exists.

**Parameters**

- `$path` `string`
- `$recursive` `bool`

### rename()

```php
public static function rename(string $from, string $to): void
```

Also across file system boundaries, then like PHP rename() as copy and delete.

**Parameters**

- `$from` `string`
- `$to` `string`

### copy()

```php
public static function copy(string $from, string $to): int
```

**Parameters**

- `$from` `string`
- `$to` `string`

**Returns** `int`: bytes copied; copying a file onto itself is an error

### remove()

```php
public static function remove(string $path): void
```

**Parameters**

- `$path` `string`

### removeDir()

```php
public static function removeDir(string $path, bool $recursive = false): void
```

An empty directory; with $recursive, also with its contents.

**Parameters**

- `$path` `string`
- `$recursive` `bool`
