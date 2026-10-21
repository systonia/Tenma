---
title: "Server"
description: "An HTTP server in fiber mode: every request runs in its own Fiber, and waiting (a query, a fetch, a sleep) suspends only that request."
order: 100
generated: true
---

`Http\Server` · final class · namespace [`Http`](index.md)

```php
final class Server
```

An HTTP server in fiber mode: every request runs in its own Fiber, and waiting (a query, a fetch,
a sleep) suspends only that request. The handler gets a Request and returns a Response, a
StreamedResponse, a WebSocketResponse or a string.

## Properties

### $port

```php
public readonly int $port;
```

## Methods

### __construct()

```php
public function __construct(
    public readonly int $port,
    private readonly mixed $handler,
    array|string|null $static = null,
)
```

With $static, tenma serves files itself (GET/HEAD, without PHP): ['/assets' => __DIR__ . '/public/assets',
'/favicon.svg' => __DIR__ . '/public/favicon.svg']. Under a prefix it looks in the directory; for
a file only its exact path matches; the longest prefix wins. Anything not found and all paths
outside go to the handler, the latter without touching the file system. A string means ['/' => $path]:
then every request is looked up, which costs PHP routes throughput. Files and directories
starting with a dot stay hidden.

**Parameters**

- `$port` `int`
- `$handler` `callable(`[`Request`](Request.md)`): (`[`Response`](Response.md)`|`[`StreamedResponse`](StreamedResponse.md)`|`[`WebSocketResponse`](WebSocketResponse.md)`|string)`
- `$static` `string|array<string, string>|null`

### start()

```php
public function start(): void
```

Registers the server: it listens once the script has finished and keeps the process alive.
