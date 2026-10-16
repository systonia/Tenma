---
title: "Tenma"
description: "Tenma's API: an HTTP server and asynchronous work in Fibers."
order: 100
generated: true
---

`Tenma` · final class

```php
final class Tenma
```

Tenma's API: an HTTP server and asynchronous work in Fibers. Classes live under `Tenma\`
(Tenma\Http\Request, Tenma\Task, Tenma\AsyncLocal, Tenma\Fs, Tenma\Db\Postgres).

## Methods

### serve()

```php
public static function serve(int $port, callable $handler, array|string|null $static = null): void
```

Starts an HTTP server. It runs once the script has finished and keeps the process alive.
The handler gets every request and returns a Response or a string.
With $static, Tenma serves files itself (GET/HEAD, without PHP): ['/assets' => __DIR__ . '/public',
'/favicon.ico' => __DIR__ . '/favicon.ico']. Under a prefix it looks in the directory; for
a file only its exact path matches; the longest prefix wins. Anything not found and all paths
outside go to the handler, the latter without touching the file system. A string means ['/' => $path]:
then every request is looked up, which costs PHP routes throughput. Files and directories
starting with a dot stay hidden.

**Parameters**

- `$port` `int`
- `$handler` `callable(`[`Request`](Tenma/Http/Request.md)`): (`[`Response`](Tenma/Http/Response.md)`|`[`StreamedResponse`](Tenma/Http/StreamedResponse.md)`|`[`WebSocketResponse`](Tenma/Http/WebSocketResponse.md)`|string)`
- `$static` `array|string|null`

### serveClassic()

```php
public static function serveClassic(int $port, callable $app): void
```

Starts an HTTP server for classic PHP code, for example a framework with its own front controller.
Before every call of $app, $_SERVER, $_GET, $_POST, $_FILES, $_COOKIE, $_REQUEST and php://input
are ready as under FPM; header(), http_response_code() and everything $app outputs become the response.
exit() and die() only end the request. $app also gets the raw body as an argument.

**Parameters**

- `$port` `int`
- `$app` `callable`

### async()

```php
public static function async(callable $work): \Tenma\Task
```

Starts $work in its own Fiber and returns a task; await() fetches the result later.
Called at script startup, $work starts once the event loop is running: background work.

**Parameters**

- `$work` `callable(): mixed`

**Returns** [`Task`](Tenma/Task.md)

### all()

```php
public static function all(\Tenma\Task|callable ...$tasks): array
```

Runs everything concurrently and returns the results with the same keys:
Tenma::all(user: fn () => …, weather: fn () => …). If one fails, all() cancels
the others, waits until they have cleaned up, and then throws the first error: nothing is left
behind in the background that still uses connections, transactions or the stream.

**Parameters**

- `...$tasks` `callable(): mixed|`[`Task`](Tenma/Task.md)

**Returns** `array<array-key, mixed>`

### detach()

```php
public static function detach(callable $work): \Tenma\Task
```

Like async(), but the task belongs to no request: if the client leaves or the request ends with
exit(), it keeps running. For work that must not be left half done (payment and order,
email after the response). A waiter that gets cancelled only stops waiting.

**Parameters**

- `$work` `callable`

**Returns** [`Task`](Tenma/Task.md)

### debugger()

```php
public static function debugger(): void
```

Stops here when an editor is connected to tenma --inspect.
Does nothing otherwise.

### reloadedBy()

```php
public static function reloadedBy(): ?string
```

Under --watch: the file whose change made the script run again, null on the first run and without
--watch. A framework can tell from it whether only a part of the app changed.

**Returns** `?string`

### diagnostics()

```php
public static function diagnostics(string $source, array $list): void
```

Replaces the problems $source reports: the editor shows them at their line (through `tenma lsp`), and new
ones go to stderr as `path:line:column: error: message`, the form problem matchers and quickfix read. An
empty list clears them. Only under --watch, which also clears the script's lists when it reloads it;
otherwise it does nothing. $source names the reporter: 'waypoint-live', 'my-app:checks'. A relative file
counts from the working directory.

**Parameters**

- `$source` `string`
- `$list` `list<array{file: string, line: int, message: string, severity?: 'error'|'warning'|'info', column?: int}>`

### sleep()

```php
public static function sleep(float $seconds): void
```

Waits without blocking the server. Other requests keep running.

**Parameters**

- `$seconds` `float`

### setTimeout()

```php
public static function setTimeout(callable $callback, float $seconds): \Tenma\Timer
```

Calls $callback once after $seconds seconds, in its own Fiber. Works
at script startup and in asynchronous code. A script without a server runs until all timers have
fired. With several workers, each worker schedules its own timers, also after every restart.

**Parameters**

- `$callback` `callable`
- `$seconds` `float`

**Returns** [`Timer`](Tenma/Timer.md)

### setInterval()

```php
public static function setInterval(callable $callback, float $seconds): \Tenma\Timer
```

Calls $callback every $seconds seconds until cancel(); keeps a script without a server alive.

**Parameters**

- `$callback` `callable`
- `$seconds` `float`

**Returns** [`Timer`](Tenma/Timer.md)

### publish()

```php
public static function publish(string $channel, string $message): void
```

Sends $message to every subscription of $channel, in every worker of this process, this one
included, like PUBLISH in Redis. Messages are strings: workers share no PHP objects. A worker that
has not subscribed yet (still starting, restarting) misses the message.

**Parameters**

- `$channel` `string`
- `$message` `string`

### subscribe()

```php
public static function subscribe(string $channel, callable $callback): \Tenma\Subscription
```

Calls $callback with every message published to $channel, in its own Fiber, until cancel(). Works
at script startup and in asynchronous code; made in a request, it ends with the request, for
WebSockets and streams with the connection. Keeps a script without a server alive.

**Parameters**

- `$channel` `string`
- `$callback` `callable(string): mixed`

**Returns** [`Subscription`](Tenma/Subscription.md)

### fetch()

```php
public static function fetch(
    string $url,
    string $method = 'GET',
    array $headers = [],
    string $body = '',
    float $timeout = 30.0,
): \Tenma\Http\FetchResponse
```

Outgoing HTTP request, without blocking the server. Throws on network errors and when the
response is not complete within $timeout seconds;
HTTP error codes are not errors; they are in $status.

**Parameters**

- `$url` `string`
- `$method` `string`
- `$headers` `array<string, string>`
- `$body` `string`
- `$timeout` `float`

**Returns** [`FetchResponse`](Tenma/Http/FetchResponse.md)
