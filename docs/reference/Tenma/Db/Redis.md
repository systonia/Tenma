---
title: "Redis"
description: "Redis, asynchronous, one shared connection per URL and worker."
order: 100
generated: true
---

`Tenma\Db\Redis` · final class · namespace [`Tenma\Db`](index.md)

```php
final class Redis
```

Redis, asynchronous, one shared connection per URL and worker.

## Methods

### connect()

```php
public static function connect(string $url = 'redis://127.0.0.1:6379'): \Tenma\Db\Redis
```

**Parameters**

- `$url` `string`: redis://host:6379/0

**Returns** [`Redis`](Redis.md)

### command()

```php
public function command(string $name, string|int|float ...$args): mixed
```

Any Redis command: $redis->command('INCR', 'views:today').

**Parameters**

- `$name` `string`
- `...$args` `string|int|float`

**Returns** `mixed`

### get()

```php
public function get(string $key): ?string
```

**Parameters**

- `$key` `string`

**Returns** `?string`

### set()

```php
public function set(string $key, string $value, ?int $seconds = null): void
```

**Parameters**

- `$key` `string`
- `$value` `string`
- `$seconds` `?int`
