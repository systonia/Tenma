---
title: Classic mode and fiber mode
description: Run code written for PHP-FPM unchanged with Tenma::serveClassic(), and know when to use fiber mode instead.
order: 120
---

Tenma has two ways to serve HTTP:

|                          | Fiber mode: `Tenma::serve()`                                           | Classic mode: `Tenma::serveClassic()`                               |
| ------------------------ | ---------------------------------------------------------------------- | ------------------------------------------------------------------- |
| Request                  | a `Tenma\Http\Request` argument                                        | `$_GET`, `$_POST`, `$_SERVER`, `$_FILES`, `$_COOKIE`, `php://input` |
| Response                 | the handler's return value                                             | `header()`, `http_response_code()`, `echo`                          |
| Concurrency              | many requests at once, each in its own fiber                           | one request after another per worker                                |
| Waiting                  | `Tenma::sleep()`, `fetch()`, Postgres, Redis, `Fs` suspend one request | asynchronous functions throw; PDO and curl block as under FPM       |
| Streams, SSE, WebSockets | yes                                                                    | no                                                                  |
| Sessions                 | build your own (cookie + Redis/Postgres)                               | `session_start()` and `$_SESSION` as usual                          |

Both keep the app booted between requests: the script runs once, so autoloading, configuration and the container are paid for once.

## Classic mode

`serveClassic()` runs code that expects PHP-FPM, such as a framework's front controller, without changes:

```php
<?php

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/app.php';   // built once, at startup

Tenma::serveClassic(8080, function (string $rawBody) use ($app) {
    $app->handleHttp();   // reads the superglobals, calls header() and echo as usual
});
```

A self-contained example:

```php
<?php

$started = date('H:i:s');

Tenma::serveClassic(8080, function () use ($started) {
    session_start();
    $_SESSION['croissants'] = ($_SESSION['croissants'] ?? 0) + (int) ($_GET['add'] ?? 0);

    if (($_GET['format'] ?? '') === 'json') {
        header('Content-Type: application/json');
        echo json_encode(['croissants' => $_SESSION['croissants'], 'booted' => $started]);
        return;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        http_response_code(201);
        echo 'Order for ', htmlspecialchars($_POST['name'] ?? ''), " reserved.\n";
        exit;   // ends only this request
    }
    echo "{$_SESSION['croissants']} croissants in your cart; the shop booted at $started.\n";
});
```

Before every request Tenma sets up what FPM would:

- `$_SERVER`, `$_GET`, `$_POST`, `$_FILES`, `$_COOKIE`, `$_REQUEST` (POST over GET) and `php://input`; uploads go through PHP's own multipart parser, so `move_uploaded_file()` works (temporary files disappear with the next request).
- `$_SERVER` looks as behind a web server: `REMOTE_ADDR` and `SERVER_PORT` from the connection, `SERVER_NAME` from `--server-name` (default `localhost`, never from the Host header), `SCRIPT_NAME` and `PHP_SELF` as `/index.php`, `DOCUMENT_ROOT` as the script's directory. Headers containing underscores are dropped, so `x_forwarded_for` cannot pose as `X-Forwarded-For`.
- `header()`, `http_response_code()` and everything written to the output become the response. Output is collected where PHP writes it, so frameworks that flush every output buffer still write into the response.
- `exit()` and `die()` end only the request.
- `ini_set()` and `set_time_limit()` changes are undone after the request; `php://input` handles are closed.
- `register_shutdown_function()` runs after every request, as under FPM, with `error_get_last()` available for error pages; its output is still part of the response. Functions registered during script startup only run when the worker ends.
- Sessions work with `session_start()` and `$_SESSION`. At the end of every request Tenma writes and closes the session and forgets its ID, so no visitor ever sees the previous visitor's session. With several workers, all share the session files, and concurrent requests of one session wait for the file lock, as under FPM.
- PHP errors and warnings go to the log on stderr, never into a response. After a fatal error the request still gets a 500, then the worker restarts.

Because superglobals, headers and output buffers exist only once per interpreter, classic requests run one after another in each worker. Run more workers (`--workers auto`) for more throughput, and keep request work short: a slow PDO query holds up its worker as it would hold up an FPM child.

## Fiber mode

Fiber mode is the native way: the handler gets a `Request` and returns a response, and thousands of requests can wait at the same time in one worker. Write new code for it, and move an existing app over when it waits a lot (APIs, databases, streams, WebSockets).

Rules when moving code to fiber mode:

- Read the request from the `Request` object (`$request->query`, `form()`, `files()`, `cookies()`, `header()`), never from superglobals.
- Return a `Response` instead of calling `header()` or `echo`.
- Keep per-request state out of statics, globals and singletons; pass it along or use [`AsyncLocal`](async.md#values-that-follow-a-request).
- Use the asynchronous clients: [`Tenma\Db\Postgres`](database.md) instead of PDO, [`Tenma::fetch()`](fetch.md) instead of curl, [`Tenma\Fs`](files.md) instead of `file_get_contents()`.

A PSR-15 app runs in fiber mode through [tenma/psr](https://github.com/systonia/Tenma-PSR):

```php
Tenma::serve(3000, Tenma\Psr\Psr::handler($app, new Nyholm\Psr7\Factory\Psr17Factory()));
```

A framework can also be a handler itself: [Waypoint](https://github.com/systonia/Waypoint) apps run with `Tenma::serve(8080, $app)`. The [bakery](https://github.com/systonia/Waypoint-Bakery) is one, in fiber mode: its product page waits for Postgres and two web services at once, and its order board is a WebSocket per tab.

See also: [`Tenma::serveClassic()`](../reference/Tenma.md#serveclassic), [`Tenma::serve()`](../reference/Tenma.md#serve), [Serving HTTP](http.md).
