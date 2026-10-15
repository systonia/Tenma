# tenma

[tenma.dev](https://tenma.dev) · [GitHub](https://github.com/systonia/Tenma)

PHP with Node's execution model: one process runs the script once and then serves requests continuously on a thread with an event loop. In-memory state persists between requests.

```php
Tenma::serve(8080, fn (Tenma\Http\Request $request) => "Hello World\n");
```

Asynchronous like Node, just without `await`: every request runs in its own fiber. `Tenma::sleep()` and `Tenma::fetch()` suspend only that fiber; meanwhile the event loop serves other requests.

```php

Tenma::serve(8080, function () {
    $weather = Tenma::fetch('https://wttr.in/München?format=3');   // does not block the server
    Tenma::sleep(0.2);                                         // neither does this
    return $weather->body;
});
```

Several things at once, like `Promise.all` in Node: `Tenma::all()` starts each function in its own fiber and returns the results under the same keys. `Tenma::async()` starts one and returns a `Task` whose `await()` later fetches the result or throws the error. If a function in `all()` fails, `all()` cancels the others (`Tenma\Cancelled` is thrown where they are currently waiting, and their `finally` and `catch` blocks run), waits until they have cleaned up, and then throws the first error: nothing is left running in the background. `$task->cancel()` cancels a specific task. Tasks, timers and subscriptions belong to their request: when the response is out (for a stream or WebSocket, when it ends), when the client leaves or when the request calls `exit()`, they are cancelled, a task that caught an earlier `cancel()` too; what their cleanup starts after that is cancelled at its first wait. Work that has to outlive the response goes in `Tenma::detach()`.

```php
['user' => $user, 'weather' => $weather] = Tenma::all(
    user: fn () => $db->first('SELECT * FROM users WHERE id = $1', [$id]),
    weather: fn () => Tenma::fetch('https://wttr.in/?format=3')->body,
);
$report = Tenma::async(fn () => Tenma::fetch($url));
// … do something else …
$response = $report->await();
```

Timers and background work as in Node: `Tenma::setTimeout()` and `Tenma::setInterval()` return a `Timer` with `cancel()`; their callback runs in its own fiber. Timers and `Tenma::async()` work scheduled at script startup start as soon as the event loop is running. A script without a server runs, as in Node, until no timers or ops are pending; an interval keeps it alive. `exit()` outside a request (in the script, in timers, in background work) terminates tenma with its status, including a server; a `die()` during startup prevents scheduled work from starting at all. A fatal error in startup work terminates tenma with 255. With several workers, each worker schedules its own timers and background work, including after every restart: shared jobs belong in a separate process or behind a lock in Redis.

```php
Tenma::setInterval(fn () => $cache->command('INCR', 'heartbeat'), 1.0);
Tenma::async(fn () => warmUpCaches());   // runs in the background as soon as the server starts
```

Asynchronous files: `Tenma\Fs::readFile()`, `writeFile()`, `appendFile()`, `exists()`, `stat()`, `readDir()`, `mkdir()`, `rename()`, `copy()`, `remove()`, `removeDir()`. The work runs on background threads and only the fiber waits; `file_get_contents` and friends, by contrast, block the worker. Paths behave as with PHP's file functions: relative to `chdir()`, `open_basedir` applies, null bytes are rejected, bytes are passed through unchanged. Errors throw `RuntimeException` with errno as the code. A running file op cannot be cancelled; it runs to completion. As in PHP and Node, `mkdir()` is only recursive with `recursive: true`, `rename()` also works across file system boundaries, and `copy()` onto itself is an error. Results that no longer fit within `memory_limit` become an error in the request instead of a fatal error.

Asynchronous databases in fiber mode: Postgres and Redis. While a query runs, only the fiber of that request waits.

```php
use Tenma\Db\{Postgres, Redis};

$db = Postgres::connect('postgres://app@localhost/app');
$cache = Redis::connect('redis://127.0.0.1:6379');

Tenma::serve(8080, function ($request) use ($db, $cache) {
    $user = $db->first('SELECT * FROM users WHERE id = $1', [$request->query['id']]);
    $db->transaction(function (Postgres $tx) use ($user) {
        $tx->execute('UPDATE users SET visits = visits + 1 WHERE id = $1', [$user['id']]);
    });
    $cache->command('INCR', 'visits');
    return Response::json($user);
});
```

Postgres behaves like PDO: parameters are sent as text and the server determines the type (`'42'` fits an int column, dates as strings). Postgres itself converts results with `json_agg`, so timestamps, `numeric`, `uuid` and `jsonb` arrive without a type table of our own. A `numeric` with more digits than a float holds exactly arrives as a string, as PDO returns it; others are numbers. At most `--db-pool` connections per worker and URL; anyone beyond that waits, at most 10 s, then the query throws. Inside `transaction()`, query through the transaction's own `$tx`: a query through the outer connection needs a second one, and with every connection held by such a transaction it would wait for itself. By default about 20 in all: 20 divided by the workers, at least 2 and at most 10 each (10 for one worker, 2 each for ten), well below Postgres' `max_connections` of 100. After every query, the connection is cleaned up before it goes back into the pool, with what `DISCARD ALL` does except dropping prepared statements (the driver keeps its own type lookups there) and with a `ROLLBACK` first: `SET ROLE`, `set_config`, temporary tables, advisory locks or a dangling `BEGIN` are never seen by another request. State across several queries therefore only exists inside `transaction()`. This costs about 30 % throughput for very small queries. Classic code (`serveClassic`) stays with PDO, which blocks as it does under FPM.

Redis has one connection per worker and URL, shared by all its fibers, so `command()` refuses what would change it for all of them or hold it: `MULTI`/`EXEC` and `WATCH` (use `EVAL` with a script for atomic steps), `SELECT` (put the database in the URL, `redis://host/2`), `SUBSCRIBE` (use `Tenma::subscribe()` with `--pubsub`), and blocking commands like `BLPOP` or `XREAD BLOCK`.

`Request` and `Response` in fiber mode: `$request->cookies()`, `cookie()`, `form()` (urlencoded and multipart, same semantics as `$_POST`), `files()` with `UploadedFile` (`name`, `type`, `size()`, `contents()`, `moveTo()`), plus `query`, `header()`, `json()`. Responses with `Response::json()`, `html()`, `redirect()`, chainable with `->header()` (multiple values with `append: true`) and `->cookie()` (defaults to `HttpOnly`, `SameSite=Lax`).

```php
Tenma::serve(8080, function (Request $request) {
    $avatar = $request->files()['avatar'] ?? null;
    $avatar?->moveTo('/srv/uploads/' . bin2hex(random_bytes(8)));
    return Response::redirect('/profile', 303)->cookie('notice', 'saved', expires: time() + 60);
});
```

tenma serves static files itself, without PHP:

```php
Tenma::serve(8080, $handler, static: [
    '/assets' => __DIR__ . '/public/assets',
    '/favicon.ico' => __DIR__ . '/public/favicon.ico',
]);
```

Under a prefix, tenma looks in the directory; a single file only matches exactly its own path; the longest prefix wins. Paths outside all prefixes go to PHP without touching the file system. A string (`static: __DIR__ . '/public'`) means `['/' => …]`: then every GET is checked, which costs PHP routes about a third of their throughput. If a file exists for the path (or `index.html` in the directory), it is sent with a Content-Type based on its extension, ETag/Last-Modified (304) and Range (206), in chunks; otherwise the request goes to the handler. A directory without a trailing `/` is redirected. GET and HEAD only. Files and directories starting with a dot (`.env`, `.git`), PHP source (`.php`, `.phtml`, `.phar`, …), so a front controller can sit in the directory, and symlinks pointing outside or to hidden entries are never served. The directory is resolved per request, so a deploy by symlink swap takes effect immediately; `open_basedir` applies here as well.

If the client closes the connection before the response is complete, the handler and its tasks receive `Tenma\Cancelled`: running ops (sleep, `Tenma::fetch()`, database) are cancelled instead of continuing to work for nobody. The `catch` block may still clean up, including waiting. Timers set by the handler end with it. For streams and WebSockets the response lasts as long as the connection: once it ends, the handler's remaining timers and tasks end too. Anything that must never be left half done (payment and order) is started by the handler with `Tenma::detach()`: the task belongs to no request and keeps running. `COMMIT` and `ROLLBACK` always run to completion. Limitation of hyper: after pipelining, tenma does not notice the disconnect, and a client that only closes its sending direction is treated as gone.

Values that travel with asynchronous work, like `AsyncLocalStorage` in Node: `Tenma\AsyncLocal`. Every task (`async()`, `all()`, `detach()`, timers) receives a copy of its creator's values when it starts; `set()` inside the task changes only its copy. A request starts with the values from the script. Typical use is the request ID in log lines:

```php
$requestId = new Tenma\AsyncLocal();
Tenma::serve(8080, function (Request $request) use ($requestId) {
    $requestId->set($request->header('x-request-id') ?? bin2hex(random_bytes(8)));
    Tenma::async(fn () => error_log("[{$requestId->get()}] running in the background"));
    return "ok\n";
});
```

Streaming, server-sent events and WebSockets in fiber mode: the headers go out immediately, each chunk right after; if a client cannot keep up, only its fiber waits. If the client goes away, the next write throws `ConnectionClosed`.

```php
use Tenma\Http\{EventStream, Response, Stream, WebSocket};

Tenma::serve(8080, fn ($request) => match ($request->path) {
    '/download' => Response::stream(function (Stream $out) {
        foreach (readRows() as $row) {
            $out->write(json_encode($row) . "\n");
        }
    }),
    '/ticker' => Response::events(function (EventStream $events) {
        while (true) {
            $events->send(json_encode(['time' => date('H:i:s')]), event: 'tick');
            Tenma::sleep(1);
        }
    }),
    '/chat' => Response::websocket(function (WebSocket $socket) {
        while (($message = $socket->receive()) !== null) {   // waits without blocking
            $socket->send("echo: $message");
        }
    }),
});
```

Existing frameworks run unchanged via `serveClassic()`: before every request, `$_SERVER`, `$_GET`, `$_POST`, `$_FILES`, `$_COOKIE`, `$_REQUEST` and `php://input` are set up as under FPM, with uploads handled by PHP's own multipart parser (with `move_uploaded_file()`; temp files disappear with the next request). `header()`, `http_response_code()` and the output become the response; `exit()` and `die()` only end the request. Whatever a request changes with `ini_set()` or `set_time_limit()` is reset afterwards to the state after script startup, and its `php://input` handles are closed. `register_shutdown_function()` runs after every request as under FPM: only once no PHP code is running anymore, with `error_get_last()` available for error pages, and its output is still part of the response. After a fatal error, the request still gets a response (500), then the worker restarts. Functions registered during script startup only run when the worker ends; in fiber mode this applies to all of them, and they accumulate until the worker ends. PHP errors and warnings always go to the log on stderr, never into a response. `$_SERVER` looks as it does under FPM behind nginx: `REMOTE_ADDR` and `SERVER_PORT` from the connection, `SERVER_NAME` from `--server-name` (default `localhost`, never from the client-controlled Host header), `$_COOKIE` via PHP's own parser (the first cookie of the same name wins), headers containing underscores are dropped as with nginx, `$_REQUEST` with POST over GET, `SCRIPT_NAME` and `PHP_SELF` as `/index.php`, `DOCUMENT_ROOT` as the script's directory. In fiber mode, the addresses are in the `Request` (`remoteAddress`, `serverPort`, `host()`). Sessions work as usual with `session_start()` and `$_SESSION`: at the end of every request, tenma writes and closes the session and forgets its ID, and `$_SESSION` only exists after `session_start()`. That way no visitor ever sees the previous visitor's session. With several workers, all of them share the session files; concurrent requests for the same session wait for the file lock as under FPM.

```php
$app = Waypoint\Waypoint::create();
$app->attach([...]);
Tenma::serveClassic(8080, fn () => $app->handleHttp());
```

Waypoint itself no longer needs classic mode: the app is itself a handler, `Tenma::serve(8080, $app)` runs in fiber mode, with request ID and CSRF token in `Tenma\AsyncLocal`. The demo runs this way too, with the same throughput as via `serveClassic()` (about 43,000 requests per second with one worker, `ab -k`, 50 connections) and byte-for-byte identical responses.

```
tenma app.php                    # one worker: one thread, one shared state
tenma --version                  # tenma 0.1.8 (PHP 8.5.9)
tenma --watch app.php            # reload when a file the script loaded changes, like node --watch
tenma --inspect app.php          # debugger (DAP) on 127.0.0.1:9229, like node --inspect
tenma --inspect-wait app.php     # wait until the debugger has set its breakpoints
tenma --inspect-brk app.php      # wait for the debugger, stop at the script's first line
tenma build                      # run the hooks of tenma.dev.php once (CI, Dockerfile)
tenma lsp                        # language server for editors, on stdin and stdout
tenma --workers 4 app.php        # four workers, each with its own PHP interpreter and its own state
tenma --workers auto app.php     # one worker per core
tenma --max-body 50m app.php     # largest request body, default 10m, 413 above that
tenma --max-requests 10000 app.php  # worker restarts its interpreter after N requests
tenma --db-pool 20 app.php       # at most 20 Postgres connections per worker and URL
tenma --pubsub redis://cache app.php   # Tenma::publish() reaches every process on that Redis
tenma tasks.php import --force   # everything after the script ends up in $argv, as with php
tenma -d memory_limit=512M -c prod.ini app.php   # PHP settings as with php -d and php -c
tenma --server-name shop.example app.php         # $_SERVER['SERVER_NAME'] like server_name in nginx
tenma --request-timeout 10 app.php               # headers and body must arrive within 10 s (default 30)
tenma --max-connections 4096 app.php             # open connections per worker, default 1024, 503 above that
```

Limits: a request's headers and body must arrive within `--request-timeout` (otherwise disconnect or 408, against Slowloris), and a write to a client may stall no longer than that: a client that stops reading is disconnected, and a stream writer waiting for it gets `ConnectionClosed`. Each worker holds at most `--max-connections` open connections; more get a 503 right away. Bodies and WebSocket messages are at most `--max-body` in size. A WebSocket client may send at most 256 messages that PHP has not read yet, then tenma disconnects it. `Tenma::fetch()` has a `timeout` (default 30 s); connections to Postgres, Redis and via `Tenma::fetch()` must be established within 10 s.

Robust: a fatal PHP error (out of memory, stack overflow) restarts only the interpreter of the affected worker and runs the script there again; the request gets a 500. Ctrl+C and SIGTERM stop accepting connections and let running requests finish for up to 10 seconds. PHP's own signal handlers are disabled; signals belong to Rust.

`--watch` is for development: tenma watches every file the script loaded, including classes the autoloader loads later, and restarts the workers' interpreters when one of them changes (different content, not just a new timestamp). The port stays open, requests during the reload wait instead of failing; in-memory state starts over, as with `node --watch`. A script that fails to load after a change answers 503 until the next change; changed ports restart tenma as a whole; a script without servers runs again. Running requests get 1 second to finish instead of 10, and `opcache.revalidate_freq` defaults to 0.

Build steps for `--watch` (CSS, bundles, caches) come from a `tenma.dev.php` next to the script. It returns a list of hooks, like the plugins of a Vite config; production code never loads it:

```php
<?php
return [
    // A command: its own process, no shell, in this directory; output prefixed with [css]
    new Tenma\Hook(
        inputs: ['templates/**/*.php', 'assets/app.css'],
        outputs: ['public/app.css'],
        run: ['vendor/bin/tailwindcss', '-i', 'assets/app.css', '-o', 'public/app.css'],
        name: 'css',
    ),
    // PHP: gets the changed paths (empty on the first run), may await Tenma's async functions
    new Tenma\Hook(inputs: ['src/Routes/**/*.php'], outputs: ['var/routes.php'], run: fn (array $changed) => RouteCache::write()),
];
```

A hook runs once at startup, before the first request, and again when a file matching its `inputs` changes. Globs are relative to `tenma.dev.php`: `*` and `?` within a name, `**` across directories. Tenma orders the hooks by their files: one reading another's `outputs` runs after it, and only if that output really changed. A cycle is rejected at startup. A hook's own outputs never trigger it again; one that writes undeclared files into its own inputs is stopped after five runs in a row. A failing hook (exception, exit status ≠ 0) skips the hooks after it, the server keeps running. A changed `tenma.dev.php`, or a file it or a hook loaded, reloads the hooks. The script starts once the first run is over, at the latest after 10 seconds (a command with its own `--watch` never ends). A command's processes, including those it started itself, get SIGTERM when the hook ends or is reloaded and SIGKILL 2 seconds later. `exit()` in a hook ends tenma. `tenma build` runs the hooks once in order and exits with 1 if one fails or they form a cycle.

`.tenma-ignore` next to `tenma.dev.php` lists what below the hooks' directories is no input, so a glob like `**/*.php` neither triggers on nor reads every file in `vendor/`. One entry per line, `#` for comments: with a slash a path in the project (`storage/cache`), without one any file or directory name (`node_modules`, `*.log`). Without the file, `.git`, `node_modules` and `vendor` are left out. A changed `.tenma-ignore` reloads the hooks.

`--inspect` starts a debugger that speaks the Debug Adapter Protocol: connect Neovim (`nvim-dap`), Zed, Helix or Emacs (`dape`) as a DAP server on `127.0.0.1:9229` (`--inspect=9230` or `--inspect=host:port` for another address; one reachable from the network gets a warning, a debugger can run any code). VS Code needs the extension [Tenma-VSCode](https://github.com/systonia/Tenma-VSCode): it starts the script on F5 or attaches to a running `--inspect`. Line breakpoints (one on a line without its own statement, such as a method's head or inside a `return [` over several lines, moves to the next statement, as in Node; one on a function's closing brace stops whenever it returns, with the return value among the variables, and a step stops there too), also with a condition, a hit count (`5`, `>= 5`, `% 3`) or as logpoints (`{expression}` in the message), and `Tenma::debugger()` in the code, like `debugger;` in JavaScript (without an editor it does nothing), continue, step over, in and out, the stack and the variables of each frame, arrays and objects expandable. Watch expressions, hovers and the debug console evaluate PHP in the selected frame of the stopped request, with its variables and `$this`; the console also runs statements. Variables, array elements and properties can be changed: a shared array is copied first, as PHP would, and property types and `readonly` apply. Evaluated code cannot wait (`Tenma::sleep()` throws a `FiberError` there), its exceptions come back as errors, and after a fatal error in it the worker restarts once it runs on. `echo` and the error log also appear in the debug console. Every request, timer and `Tenma::async()` task is a thread of its own: while stopped, the editor lists them, named after method and URI, and shows where each waiting one waits. A step stays in its request: stepping over `Tenma::sleep()` or a query lets other requests run meanwhile and stops at the next line of the same one. Exceptions the app does not catch stop where they are thrown, with the frames still alive (the filter "All exceptions" also stops at caught ones; a condition on either filter limits it to classes, e.g. `RuntimeException, App\NotFound`, subclasses included). A stopped request stops its worker, with its other requests and timers, as in Node. `--inspect` runs one worker and without opcache; Tenma's standard library is never stepped into. With `--watch` the editor stays connected across reloads and the breakpoints keep applying; a change while stopped reloads after continuing. `--inspect-wait` waits until the editor has set its breakpoints, so they apply from the script's first statement on; `--inspect-brk` also stops there. The editor's `stopOnEntry` overrides both, as in a VS Code `launch.json`.

With several workers, the script runs once per worker, and each has its own memory: an in-memory counter or cache exists per worker, as with Node `cluster` or FrankenPHP. Shared state then belongs in a database or Redis.

Messages between workers, like PUBLISH and SUBSCRIBE in Redis: `Tenma::publish()` hands a string to every subscription of its channel in every worker of the process, the publishing one included. `Tenma::subscribe()` runs its callback with each message in its own fiber and returns a `Subscription` with `cancel()`. Made at script startup, it lasts as long as the worker; made in a request, it ends with the request, for WebSockets and streams with the connection. Messages are strings, since workers share no PHP objects: send what changed (an ID, JSON) and let each worker update its own state. A worker that is still starting or restarting misses what is published meanwhile. A subscription keeps a script without a server alive.

Several processes of the same app, on one machine or behind a load balancer, start with `--pubsub redis://host`: messages then go through PUBLISH and SUBSCRIBE in Redis, and every worker of every process hears them. The PHP code stays the same. The channels are Redis channels of the same name. If Redis goes away, tenma reports it once and reconnects every second; messages published meanwhile go out after the reconnect, subscriptions in other processes miss what was published while they were cut off.

```php
Tenma::subscribe('prices', function (string $message) use (&$prices) {
    $prices = json_decode($message, true);   // every worker gets the new prices
});
Tenma::publish('prices', json_encode($prices));
```

## Install

```
curl -fsSL https://raw.githubusercontent.com/systonia/Tenma/main/packaging/install.sh | sh
```

The script picks the way that fits:

| System | Installs | By hand |
|---|---|---|
| macOS on Apple Silicon | the Homebrew formula, with the libraries it links | `brew install systonia/tap/tenma` |
| Debian 13 | the `.deb` from the release, apt brings the libraries | `apt install ./tenma_0.1.4_amd64.deb` |
| Ubuntu 24.04 | the `.deb` built on Ubuntu 24.04 | `apt install ./tenma_0.1.4_ubuntu24.04_amd64.deb` |
| other Linux | the binary in `~/.local/bin` (`TENMA_DIR`); names the libraries still missing | `tenma-linux-x64.tar.gz` from the [release](https://github.com/systonia/Tenma/releases) |
| anything with Docker | – | `docker run -p 8080:8080 -v "$PWD:/app" ghcr.io/systonia/tenma /app/index.php` |

`TENMA_VERSION=0.1.4` installs a given version on Linux. The tarballs and the image are built on Debian 13 and link its libraries; Ubuntu 24.04 has older ones (ICU 74) and gets a `.deb` of its own.

## IDEs and PHPStan

The PHP API lives inside the binary, so IDEs and PHPStan don't know `Tenma` on their own. Install the stubs as a dev dependency ([systonia/Tenma-Stubs](https://github.com/systonia/Tenma-Stubs)):

```bash
composer require --dev tenma/stubs
```

PhpStorm and Intelephense pick them up from `vendor/`; for PHPStan the package brings an `extension.neon` (automatic with `phpstan/extension-installer`). The stubs are never autoloaded, so nothing changes at runtime. `tenma --stubs` prints them for the binary at hand.

### Language server

`tenma lsp` is a language server (LSP on stdin and stdout) that runs **next to** the editor's PHP server (Intelephense, PHP Tools, PhpStorm), not instead of it: the editor merges the answers. It answers what that server cannot know. Packages add their part through a `Tenma\Lsp\Provider`, named in their `composer.json`:

```json
"extra": { "tenma": { "lsp": "Waypoint\\Live\\Editor" } }
```

A provider can bind `$this` in a file that is no class (a template) to a class: tenma then completes `$this->` and `$this->prop->` (private members too, the file runs bound to the object), shows type and doc comment on hover and leads F12 to the declaration, all by reflection. The provider adds its own completions, definitions and diagnostics; these get the editor's text, saved or not. Providers come from the project's `composer.json` and from every installed package (`vendor/composer/installed.json`).

Two processes: the server keeps the open documents, a worker (`tenma lsp --worker DIR`) loads the project's `vendor/autoload.php` and the providers. PHP cannot load a changed class again, so saving a `.php` file ends the worker and the next question starts a new one; a class shows its new members once it is saved. A provider that throws is logged on stderr and left out; one that takes longer than 5 seconds ends the worker. Loading the autoloader runs the project's code: start `tenma lsp` only in projects you trust.

VS Code and VSCodium start it through [Tenma-VSCode](https://github.com/systonia/Tenma-VSCode), in trusted workspaces. Other editors run `tenma lsp` as a language server for PHP files, e.g. Neovim:

```lua
vim.lsp.config('tenma', { cmd = { 'tenma', 'lsp' }, filetypes = { 'php' }, root_markers = { 'composer.json' } })
vim.lsp.enable('tenma')
```

## Architecture

| Path | Contents |
| --- | --- |
| `src/main.rs` | CLI, worker threads each with their own event loop (tokio, current_thread), accepting connections in the main thread, HTTP server (hyper) |
| `src/php.rs` | Safe Rust wrappers around the C layer. Rust never sees a zval. Pending requests and resuming fibers. |
| `src/ops.rs` | Asynchronous ops for PHP: `sleep` (tokio timer), `fetch` (reqwest) |
| `src/db.rs` | Postgres (tokio-postgres, pool per worker) and Redis (one shared connection per URL) as ops |
| `src/fs.rs` | File system ops for `Tenma\Fs`, on tokio's blocking threads |
| `src/pubsub.rs` | `Tenma::publish()` and `subscribe()`: channels between the workers of the process, through Redis with `--pubsub` |
| `src/files.rs` | Static files for `Tenma::serve(..., static:)`: prefixes, ETag/304, ranges, checks on the opened file |
| `src/watch.rs` | `--watch`: watches the files PHP loaded and the hooks' directories, reports real changes (content, not timestamps) |
| `src/dev.rs` | Hooks of `tenma.dev.php`: the dev thread's ops, command hooks as processes (`tokio::process`) |
| `src/inspect.rs` | `--inspect`: DAP server thread, breakpoints and steps; stops the worker inside PHP's statement handler |
| `src/streams.rs` | Streaming responses (body from a channel with backpressure) and WebSockets (tokio-tungstenite after the HTTP upgrade) |
| `shim/tenma.c` | C layer: embed PHP, `tenma_listen()` for PHP, call handlers, convert PHP values to C values, catch fatal errors with `zend_try` |
| `stdlib/tenma.php` | Standard library in PHP, embedded in the binary: `Tenma::serve()`, `Tenma::serveClassic()`, `Request`, `Response` |
| `stdlib/stubs.php` | `tenma --stubs`: prints the public API as stubs by reflection, for IDEs and PHPStan |
| `stdlib/lsp.php` | `tenma lsp`: the language server and its worker, in PHP; the providers' interface `Tenma\Lsp\Provider` is in `stdlib/tenma.php` |
| `php-build/build.sh` | Builds PHP 8.5 from source as `libphp.a` (embed SAPI, ZTS), linked statically. Extensions: PDO with MySQL, Postgres and SQLite, mysqli, pgsql, sqlite3, openssl, curl, sodium, intl, bcmath, gmp, gd, zip, mbstring, session, sockets, exif, XML, fileinfo, filter. The libraries for these come from the macOS SDK and Homebrew (list in the script) and are linked dynamically. |
| `demo/waypoint/` | Waypoint app (framework [Waypoint](https://github.com/systonia/Waypoint)) with a waypoint API; runs under tenma in fiber mode and unchanged under `php -S` |
| `tests/http.rs` | Starts the binary and sends real requests |
| `tests/lsp.rs` | Speaks LSP to `tenma lsp` on the project in `tests/fixtures/lsp` |

Flow: the main thread starts PHP (modules); each worker thread gets its own interpreter (ZTS, `tenma_thread_start`) and loads the standard library, then the script. The first worker starts alone; if the script registers no server, it was an ordinary script and tenma exits. Otherwise the remaining workers start, and the main thread opens the ports, accepts connections and distributes them round-robin across the workers. A connection stays with its worker. As with the CLI, `$_SERVER['SCRIPT_FILENAME']` points to the script. `Tenma::serve()` only registers a handler via `tenma_listen()`. Once the script has finished, Rust opens the ports. Each request calls the PHP handler synchronously on the loop thread. Asynchronously: the handler runs in a fiber. When it calls an op (`\tenma_op_sleep`, `\tenma_op_fetch`), Rust starts a task and returns an op ID, and the fiber suspends with `Fiber::suspend()`. When the task is done, Rust calls `Tenma\Internal\complete()`, which resumes the fiber with the result. Finished handlers respond via `\tenma_respond($rid, …)`, and Rust passes the response to the waiting HTTP request. Classic mode has no fibers, because superglobals, headers and output buffers exist only once; there, requests run one after another and asynchronous functions throw.

`exit()` and `die()` end in PHP with a `zend_bailout` as soon as the handler was called from outside, exactly like a fatal error. The C layer tells the two apart by the last PHP error: without a fatal error, PHP unwound cleanly, the request is answered and the worker keeps running.

tenma collects the output of classic requests where PHP writes it (the SAPI's `ub_write`), not in an `ob_start()` buffer: frameworks that flush all output buffers when sending (Symfony, Laravel, WordPress) thus still write into the response. At the end, all buffers still open are flushed, including those that cannot be removed.

Before every request, the C layer resets headers, status and the "headers already sent" state. An exception in the handler yields a 500 and the server keeps running. A fatal PHP error restarts only the worker's interpreter (see above). Errors and warnings go to stderr, never into a response.

## Commands

```
brew install pkgconf openssl@3 libpq icu4c libsodium libzip gmp oniguruma libpng jpeg-turbo webp freetype
php-build/build.sh                     # once, about 5 minutes
cargo build --release                  # binary: target/release/tenma
cargo test --release
(cd demo/waypoint && composer install)
./target/release/tenma demo/waypoint/index.php
```

On Linux, `php-build/build.sh` takes the libraries from the distribution's `-dev` packages instead; the `Dockerfile` builds both on Debian 13 and keeps only the binary and the libraries it links (about 270 MB):

```
docker build -t tenma .
docker run -p 8080:8080 -v "$PWD:/app" tenma /app/index.php
docker run --rm -e USER=root $(docker build -q --target build .) cargo test --release
```

The image runs tenma as the unprivileged user `tenma` (uid 1000), so files the app writes into a mounted checkout belong to the usual first user of a Linux host; `--user "$(id -u)"` fits another one.

A tag `v*` builds the binaries for macOS arm64 and Linux x64 and arm64 as a GitHub release, and the image as `ghcr.io/systonia/tenma` (`.github/workflows/build.yml`). The Linux binaries link Debian 13's libraries: on another distribution, use the image.

Databases on one worker: one Postgres query per request about 25,000 requests per second (35,000 without the cleanup), at 200 concurrent requests with 10 connections; four Redis commands per request about 42,000.

Streaming: 500 concurrent streams of 0.6 s each on one worker, about 780 per second, 58 MB. WebSocket echo: 46,000 round trips per second over one connection, about 118,000 over four.

Workers, Waypoint demo, `GET /waypoints/1`, `ab -k` with 50 connections: 1 worker 55,000, 4 workers 165,000, 10 workers 192,000 requests per second (4 × `ab` in parallel: 207,000, at about 570 % CPU). FrankenPHP in worker mode reached 52,000 to 61,000 with the same app. Without keep-alive it stays at about 45,000 regardless of the number of workers: connection setup and the single accepting thread are the limit then.

Opcache is enabled (tenma does not count as CLI for opcache). It brings little for tenma, about 6 % throughput and some memory, because each worker compiles the code only once at startup anyway. PHP does not build the JIT with ZTS on Apple Silicon; on Linux it is built in and can be enabled with `-d opcache.jit=tracing`.

Memory under sustained load: 5 million requests against the Waypoint demo, the process stays at 22.8 MB; 2.1 million `/proxy` requests with fibers and `fetch` at 26.8 MB.

Fibers, measured with `ab -k`: a simple handler about 100,000 requests per second; `/slow` with `Tenma::sleep(0.5)` at 500 connections about 900 per second, i.e. all 500 awake at the same time; `/proxy`, which queries the same server via `fetch`, about 37,000.

Measured with `ab` on an M-series Mac, Waypoint demo, `GET /waypoints/1`: tenma about 42,000 requests per second, `php -S` about 17. The comparison is unfair in tenma's favor: `php -S` has no opcache, and Waypoint without a route cache re-reads all attributes on every request. Under FPM with opcache and trust mode the gap would be smaller, but the core point stands: tenma boots the app once.

## Not yet

- Asynchronous MySQL (`mysql_async`), TLS for Postgres connections.
- More ops: sockets, DNS. Blocking PHP functions such as PDO or `file_get_contents` still hold up their worker.
- Waiting at the top level of a script, outside `Tenma::async()` (`Tenma::sleep()` during startup throws; wrap it in `Tenma::async()`).
- Streaming for large request bodies (up to the limit they are buffered completely in memory).
- A timeout for handlers that compute instead of waiting: an infinite loop holds up its worker.
- Multiple accepting threads (`SO_REUSEPORT`) for many short connections without keep-alive.

## License

Tenma is licensed under either of [MIT](LICENSE-MIT) or
[Apache-2.0](LICENSE-APACHE), at your option.
Binaries embed PHP (PHP License 3.01), see [NOTICE](NOTICE).
