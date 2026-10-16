---
title: "Task"
description: "Work that runs in its own Fiber while the caller carries on; await() fetches its result."
order: 100
generated: true
---

`Tenma\Task` · final class · namespace [`Tenma`](index.md)

```php
final class Task
```

Work that runs in its own Fiber while the caller carries on; await() fetches its result.
Created with async() or all(). Belongs to the request it was created in.

## Properties

### $request

```php
public readonly ?int $request;
```

## Methods

### await()

```php
public function await(): mixed
```

Waits until the task is done, without blocking the server; returns its result or throws its error.

**Returns** `mixed`

### cancel()

```php
public function cancel(?\Throwable $error = null): void
```

Cancels the task: wherever it is waiting (op, task, all()), $error is thrown (default: Cancelled);
finally and catch blocks run. If it is not waiting right now, its next wait throws.

**Parameters**

- `$error` `?`[`Throwable`](https://www.php.net/manual/en/class.throwable.php)

### isDone()

```php
public function isDone(): bool
```

**Returns** `bool`

### __destruct()

```php
public function __destruct()
```

If nobody ever calls await(), the error is not silently lost.
