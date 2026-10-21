---
title: "Plugin"
description: "A native plugin (`tenma --plugin ./libname.so`, or `plugins` in tenma.json): a shared library in the process, with threads of its own, that PHP talks to by name."
order: 100
generated: true
---

`Tenma\Plugin` · final class · namespace [`Tenma`](index.md)

```php
final class Plugin
```

A native plugin (`tenma --plugin ./libname.so`, or `plugins` in tenma.json): a shared library in the
process, with threads of its own, that PHP talks to by name. Arguments and results are bytes; the
plugin and the PHP package around it agree on the format, and the package offers typed functions over
these calls. A plugin lives as long as the process and survives worker restarts.

## Methods

### call()

```php
public static function call(string $plugin, string $function, string $input = ''): string
```

Calls the plugin's function $function synchronously, on this thread: for quick calls. The result
bytes; throws RuntimeException with the plugin's message when it fails.

**Parameters**

- `$plugin` `string`
- `$function` `string`
- `$input` `string`

**Returns** `string`

### await()

```php
public static function await(string $plugin, string $function, string $input = ''): string
```

Starts the plugin's function $function and waits for its result without blocking the server: the
plugin completes it later, from any of its threads. Throws RuntimeException with the plugin's
message when it fails.

**Parameters**

- `$plugin` `string`
- `$function` `string`
- `$input` `string`

**Returns** `string`

### adopt()

```php
public static function adopt(string $plugin, \Http\WebSocket $socket): int
```

Hands an accepted WebSocket over to the plugin: from now on the plugin reads and writes it, from
its own threads, and PHP no longer can. Returns the connection ID the plugin knows it by. The
handler of Response::websocket() may return after it; the connection stays open. Throws
RuntimeException if the plugin does not adopt connections or the socket is not available.

**Parameters**

- `$plugin` `string`
- `$socket` [`WebSocket`](../Http/WebSocket.md)

**Returns** `int`
