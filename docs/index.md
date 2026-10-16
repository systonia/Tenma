---
title: Tenma
description: PHP 8.5 as a long-running process with an event loop, fibers and a built-in HTTP server.
order: 1
---

Tenma runs a PHP script once and then keeps the process alive: the script registers an HTTP server with `Tenma::serve()`, and every request is answered by the same process, from an event loop. What the script built at startup (the router, the container, a cache, open database connections) stays in memory for every request.

Every request runs in its own [fiber](https://www.php.net/manual/en/language.fibers.php). When it waits — for `Tenma::sleep()`, an outgoing HTTP request, a Postgres query, a Redis command or a file — only that fiber is suspended, and the event loop serves other requests meanwhile. There is no `await` keyword and no promise type in the code: an asynchronous call simply returns its result.

Tenma is a single binary with PHP 8.5 (ZTS) and its usual extensions built in. Its PHP API (`Tenma`, `Tenma\Http`, `Tenma\Db`, `Tenma\Fs`) is part of the binary, not a Composer package.

## Install

```bash
curl -fsSL https://raw.githubusercontent.com/systonia/Tenma/main/packaging/install.sh | sh
```

The script installs the Homebrew formula on macOS (Apple Silicon), a `.deb` on Debian 13 and Ubuntu 24.04, and the plain binary in `~/.local/bin` on other Linux systems. By hand:

| System | Command |
|---|---|
| macOS on Apple Silicon | `brew install systonia/tap/tenma` |
| Debian 13, Ubuntu 24.04 | `apt install ./tenma_<version>_amd64.deb` from the [release](https://github.com/systonia/Tenma/releases) |
| other Linux | `tenma-linux-x64.tar.gz` from the release, binary into `~/.local/bin` |
| Docker | `docker run -p 8080:8080 -v "$PWD:/app" ghcr.io/systonia/tenma /app/index.php` |

Check it with `tenma --version`, which prints the Tenma and PHP versions.

## A first app

Save this as `app.php`:

```php
<?php

use Tenma\Http\{Request, Response};

$visits = 0;   // lives as long as the process: shared by every request

Tenma::serve(8080, function (Request $request) use (&$visits) {
    $visits++;
    return match ($request->path) {
        '/' => Response::html("<h1>Hello</h1><p>Visit number $visits.</p>"),
        '/slow' => (function () {
            Tenma::sleep(1);   // suspends this request only; others keep being served
            return "done\n";
        })(),
        '/api/time' => Response::json(['time' => date(DATE_ATOM)]),
        default => new Response("Not found\n", 404),
    };
});

echo "Listening on http://localhost:8080\n";
```

Run it:

```bash
tenma app.php
```

The script runs once: it registers the server and prints the line, then Tenma opens the port. `curl localhost:8080` counts up on every call, because `$visits` lives in the process. Ten parallel calls to `/slow` all finish after about one second: each waits in its own fiber.

During development, `tenma --watch app.php` restarts the PHP side whenever a file the script loaded changes, without closing the port.

## How these docs are organised

- **[Guides](guides/index.md)** explain one feature each, with code you can run: serving HTTP, asynchronous work, streams, WebSockets, databases, files, development tools, deployment.
- **[Reference](reference/index.md)** has one page per class of Tenma's PHP API, generated from the code with `tenma docs`: every method with its signature, parameters and return value.

Start with [Serving HTTP](guides/http.md) and [Fibers and asynchronous work](guides/async.md); they cover what every Tenma app uses. [Classic mode and fiber mode](guides/classic-mode.md) explains how to run an existing framework that relies on `$_GET`, `header()` and `echo`.
