---
title: Guides
description: The model of the runtime, its APIs, the tools and how to run it in production.
order: 10
---

The guides come in four parts. Concepts explain how tenma runs your code. The API pages describe one
module each, with examples you can save and run with `tenma <file>.php`. The
[reference](../reference/index.md) lists every class and method in detail.

## Concepts

- [Process and workers](process.md): the main thread, worker threads, restarts, scripts without a
  server
- [Event loop and fibers](event-loop.md): what waits without blocking, and what blocks a worker
- [Plain PHP under tenma](compatibility.md): PHP's own sockets, `http://`, PDO and mysqli wait without
  blocking; what still blocks; `--pdo-pgsql native`
- [Lifetime of a request](request-lifetime.md): what a request owns, cancellation, `Threading\Task::detach()`,
  `exit()`
- [State in memory](state.md): what lives as long as the worker, per-request values, shared state
- [Fiber mode and classic mode](classic-mode.md): `Http\Server` and `Http\ClassicServer`

## APIs

- [HTTP server](http.md): `Http\Server`, `Request`, `Response`, cookies, uploads, static files
- [HTTP client](fetch.md): `Http\Client`
- [Tasks, timers and AsyncLocal](async.md): `Threading\Task`, `Threading\Timer`, `AsyncLocal`
- [Streams and server-sent events](streams.md): `Response::stream()`, `Response::events()`
- [WebSockets](websockets.md): `Response::websocket()`
- [File system](files.md): `FileSystem\FileInfo`, `FileSystem\DirectoryInfo`
- [Postgres, Redis and pub/sub](database.md): `Data\Postgres`, `Data\Redis`, `Messaging\Channel`
- [Durable functions](durable.md): `Threading\Durable`, `Threading\Signal`

## Tooling

- [Command line](command-line.md): every subcommand and option, exit codes, stubs
- [Watch mode and build hooks](watch.md): `--watch`, `tenma.dev.php`, `tenma build`
- [Debugger](debugger.md): `--inspect` and editor setup
- [Language server](language-server.md): `tenma lsp` and its provider interfaces
- [Diagnostics](diagnostics.md): problems a running app reports to the editor
- [Record and replay](replay.md): `--record`, `tenma replay`, stepping back in the debugger
- [Reference pages](reference-docs.md): `tenma docs`

## Operations

- [Configuration and php.ini](configuration.md): PHP settings, extensions, opcache
- [Deployment](deployment.md): Docker, systemd, Homebrew, reverse proxy, signals, limits, logs
