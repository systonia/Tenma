---
title: "Durable"
description: "A function that survives crashes, restarts and deployments: written as plain sequential code, it may wait for a signal or a timer for days and goes on where it was, in whichever process runs."
order: 100
generated: true
---

`Threading\Durable` · final class · namespace [`Threading`](index.md)

```php
final class Durable
```

A function that survives crashes, restarts and deployments: written as plain sequential code, it may
wait for a signal or a timer for days and goes on where it was, in whichever process runs. Every
result it gets from outside (a query, a fetch, time(), random_int()) goes to its journal in Postgres
(`tenma --durable postgres://…`) before it sees it; resuming runs the code again and answers from the
journal until the code is past what it did before.

Side effects the journal cannot cover (an email through blocking SMTP) run at least once: give them
an idempotency key. A durable function needs a running server, or a script that stays alive.

## Constants

### TIMER

```php
public const float TIMER = 1.0;
```

Task::delay() of this many seconds or more inside a durable function waits in the database.

## Methods

### define()

```php
public static function define(string $name, callable $work, int $version = 1): void
```

Defines the durable function $name: $work gets the key it was started with. Define it at startup, in
every worker; a process that resumes the function finds it by name. Code that changes between
versions gives a higher $version: a journal of another version fails loud instead of diverging.

**Parameters**

- `$name` `string`
- `$work` `callable`
- `$version` `int`

### start()

```php
public static function start(string $name, string $key): bool
```

Starts the function $name once for $key, across every process: a second start with a running or
finished key does nothing. Returns whether this call started it.

**Parameters**

- `$name` `string`
- `$key` `string`

**Returns** `bool`
