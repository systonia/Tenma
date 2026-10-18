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
- [Lifetime of a request](request-lifetime.md): what a request owns, cancellation, `Tenma::detach()`,
  `exit()`
- [State in memory](state.md): what lives as long as the worker, per-request values, shared state
- [Fiber mode and classic mode](classic-mode.md): `Tenma::serve()` and `Tenma::serveClassic()`

## APIs

- [HTTP server](http.md): `Tenma::serve()`, `Request`, `Response`, cookies, uploads, static files
- [HTTP client](fetch.md): `Tenma::fetch()`
- [Tasks, timers and AsyncLocal](async.md): `Tenma::async()`, `Tenma::all()`, `Tenma::sleep()`,
  timers, `AsyncLocal`
- [Streams and server-sent events](streams.md): `Response::stream()`, `Response::events()`
- [WebSockets](websockets.md): `Response::websocket()`
- [File system](files.md): `Tenma\Fs`
- [Postgres, Redis and pub/sub](database.md): `Tenma\Db\Postgres`, `Tenma\Db\Redis`,
  `Tenma::publish()`, `Tenma::subscribe()`

## Tooling

- [Command line](command-line.md): every subcommand and option, exit codes, stubs
- [Watch mode and build hooks](watch.md): `--watch`, `tenma.dev.php`, `tenma build`
- [Debugger](debugger.md): `--inspect` and editor setup
- [Language server](language-server.md): `tenma lsp` and its provider interfaces
- [Diagnostics](diagnostics.md): problems a running app reports to the editor
- [Reference pages](reference-docs.md): `tenma docs`

## Operations

- [Configuration and php.ini](configuration.md): PHP settings, extensions, opcache
- [Deployment](deployment.md): Docker, systemd, Homebrew, reverse proxy, signals, limits, logs
