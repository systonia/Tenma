---
title: "Shutdown"
description: "What runs when tenma stops."
order: 100
generated: true
---

`Process\Shutdown` · final class · namespace [`Process`](index.md)

```php
final class Shutdown
```

What runs when tenma stops.

## Methods

### register()

```php
public static function register(callable $handler): void
```

Calls $handler when tenma stops (Ctrl+C, SIGTERM), in its own fiber, before the native plugins
shut down and the interpreter ends: flush a queue, close a connection pool, ask a plugin for a
final save. Every worker runs its own handlers. tenma waits for them up to --stop-timeout (10 s
by default), then logs that some had not finished and goes on. A second Ctrl+C skips them.

**Parameters**

- `$handler` `callable(): mixed`
