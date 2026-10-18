---
title: Command line and deployment
description: Every tenma option, workers and limits, signals and restarts, and running Tenma with Docker, systemd or Homebrew.
order: 130
---

## The command line

```text
tenma [options] <script.php> [arguments …]
tenma build [tenma.dev.php]
tenma lsp
tenma docs [<path>...] [--out <dir>]
tenma --stubs
tenma --version
```

Everything after the script belongs to the script and ends up in `$argv`, as with `php`: `tenma index.php db:migrate` runs the bakery's migrations as a CLI task instead of serving.

| Option                      | Default            |                                                                                                       |
| --------------------------- | ------------------ | ----------------------------------------------------------------------------------------------------- |
| `--workers N`, `-w N`       | 1                  | worker threads, each with its own PHP interpreter and state; `auto` = one per CPU core                |
| `--max-body SIZE`           | `10m`              | largest request body and WebSocket message (`k`, `m`, `g`); 413 above it                              |
| `--max-requests N`          | none               | a worker restarts its interpreter after N requests (for leaking code)                                 |
| `--max-connections N`       | 1024               | open connections per worker; more get a 503 at once                                                   |
| `--request-timeout SECONDS` | 30                 | headers and body must arrive in time (408 or disconnect), and a write to a client may stall no longer |
| `--db-pool N`               | 20 / workers, 2–10 | Postgres connections per worker and URL                                                               |
| `--pubsub redis://HOST`     | none               | `Tenma::publish()` goes through Redis and reaches every process                                       |
| `--server-name NAME`        | `localhost`        | `$_SERVER['SERVER_NAME']` in classic mode                                                             |
| `-d name=value`             |                    | a PHP setting, as with `php -d`                                                                       |
| `-c FILE\|DIR`              |                    | a `php.ini`, as with `php -c`                                                                         |
| `--watch`                   |                    | reload on changes, run the hooks of `tenma.dev.php` ([Watch mode](watch.md))                          |
| `--inspect[=[HOST:]PORT]`   | `127.0.0.1:9229`   | the debugger; `--inspect-wait`, `--inspect-brk` ([Debugger](debugger.md))                             |

`tenma build` runs the [build hooks](watch.md#tenma-build) once, `tenma lsp` is the [language server](language-server.md), `tenma docs` writes [reference pages](reference-docs.md), and `tenma --stubs` prints Tenma's PHP API as stubs for IDEs and PHPStan (also available as the Composer package `tenma/stubs`).

A server listens on every interface (`0.0.0.0`) on the port given to `Tenma::serve()`. Read the port from the environment so the same script runs everywhere:

```php
Tenma::serve((int) (getenv('PORT') ?: 8080), $handler);
```

## Scripts without a server

A script that does not call `Tenma::serve()` is an ordinary script: it runs, then keeps running while timers, tasks or subscriptions are pending, and exits. `exit($status)` outside a request (in the script, a timer or background work) ends Tenma with that status, including a running server. A fatal error during startup work ends Tenma with 255.

## Workers

```bash
tenma --workers auto index.php
```

Each worker is a thread with its own PHP interpreter and its own event loop. The script runs once per worker, so each worker has its own copy of everything in memory: an in-memory counter or cache exists per worker. Shared state belongs in Postgres or Redis, and changes to in-memory caches can be announced with [`Tenma::publish()`](database.md#messages-between-workers). The bakery runs with `tenma --workers 2 index.php` in production: each worker keeps its own copy of the weather forecast, which is fine, while orders and stock live in Postgres and the order board hears of every change through `Tenma::publish()`.

The main thread accepts connections and hands them to the workers in turn; a connection stays with its worker. One worker already serves many concurrent requests in fiber mode, so start with one per core and measure. In [classic mode](classic-mode.md), where each worker serves one request at a time, more workers mean more parallel requests.

## Restarts and signals

- **Ctrl+C or SIGTERM**: Tenma stops accepting connections and lets running requests finish for up to 10 seconds, then exits. A second signal ends it at once.
- **A fatal PHP error** (out of memory, stack overflow) answers 500 and restarts only the interpreter of that worker, which runs the script again. The other workers keep serving.
- **`--max-requests N`** restarts a worker's interpreter after N requests.

PHP's own signal handlers are disabled (and `pcntl` is not built in): signals belong to Tenma.

## Limits

Requests whose headers and body do not arrive within `--request-timeout` are cut off (against slowloris clients). A client that stops reading a response is disconnected after the same time; a streaming writer or `WebSocket::send()` waiting for it gets `ConnectionClosed`. `Tenma::fetch()` has its own `timeout` (default 30 s), and connections to Postgres, Redis and through `fetch()` must be established within 10 seconds.

A handler that computes forever (an endless loop) is not interrupted and holds up its worker; there is no execution timeout for CPU work.

## Behind a reverse proxy

Tenma speaks plain HTTP/1.1. Put a proxy such as Caddy or nginx in front for TLS, HTTP/2 and compression:

```caddyfile
sonnenkorn.test {
    reverse_proxy 127.0.0.1:8080
}
```

`$request->remoteAddress` is then the proxy's address; read the client's address from the header the proxy sets (`X-Forwarded-For`), and only trust it from your proxy.

## Docker

The image `ghcr.io/systonia/tenma` has `tenma` as its entrypoint, runs as the unprivileged user `tenma` (uid 1000), sets `PORT=8080` and stops with SIGTERM:

```bash
docker run -p 8080:8080 -v "$PWD:/app" ghcr.io/systonia/tenma /app/index.php
```

A production image for an app:

```dockerfile
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --classmap-authoritative --no-scripts

FROM ghcr.io/systonia/tenma
WORKDIR /app
COPY --from=vendor /app/vendor vendor
COPY . .
CMD ["--workers", "auto", "/app/index.php"]
```

With [build hooks](watch.md), run `tenma build` in a build stage so the image contains their outputs.

## systemd

`/etc/systemd/system/bakery.service`:

```ini
[Unit]
Description=Sonnenkorn bakery (tenma)
After=network-online.target postgresql.service
Wants=network-online.target

[Service]
User=bakery
WorkingDirectory=/srv/bakery/current
Environment=PORT=8080
Environment=APP_ENV=production
Environment=DATABASE_URL=postgres://bakery@localhost/bakery
# JWT_SECRET and CSRF_SECRET, readable by root only
EnvironmentFile=/etc/bakery/secrets.env
ExecStartPre=/usr/bin/tenma index.php db:migrate
ExecStart=/usr/bin/tenma --workers auto -c /etc/bakery/php.ini index.php
Restart=always
KillSignal=SIGTERM
TimeoutStopSec=15

[Install]
WantedBy=multi-user.target
```

`systemctl enable --now bakery`. Logs (stderr) go to the journal: `journalctl -u bakery -f`. `ExecStartPre` applies new migrations before every start; the bakery's migrate task takes an advisory lock, so two starts at once wait for each other. `TimeoutStopSec` is above Tenma's 10-second drain, so running requests can finish. The `.deb` installs the binary as `/usr/bin/tenma`; with the tarball, use its path in `~/.local/bin`.

To deploy without dropping requests, start the new release on another port, switch the proxy, then stop the old one.

## Homebrew

On macOS (Apple Silicon):

```bash
brew install systonia/tap/tenma
brew upgrade tenma
```

The formula installs the binary and the libraries it links. For a background service on a Mac, a `launchd` agent with `KeepAlive` runs `tenma` like the systemd unit above.

## Logs

Tenma writes to stderr: PHP errors and warnings, uncaught exceptions with their stack trace, and `error_log()`. Nothing goes into responses. `echo` outside classic requests goes to stdout.

See also: [Configuration and php.ini](configuration.md), [Watch mode and build hooks](watch.md).
