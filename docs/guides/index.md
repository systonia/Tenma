---
title: Guides
description: One feature per page, with examples that run as they are.
order: 10
---

Each guide covers one part of Tenma and shows the way to use it, with complete scripts you can save and run with `tenma <file>.php`. The [reference](../reference/index.md) lists every class and method in detail.

Building apps:

- [Serving HTTP](http.md): `Tenma::serve()`, requests, responses, cookies, uploads, static files
- [Fibers and asynchronous work](async.md): waiting without blocking, tasks, `all()`, timers, cancellation, `AsyncLocal`
- [Streams and server-sent events](streams.md): responses written bit by bit
- [WebSockets](websockets.md): two-way connections
- [Databases and messages](database.md): Postgres, Redis, messages between workers and processes
- [Outgoing HTTP](fetch.md): `Tenma::fetch()`
- [Files](files.md): `Tenma\Fs`
- [Classic mode and fiber mode](classic-mode.md): running code built for PHP-FPM

Developing:

- [Watch mode and build hooks](watch.md): `--watch`, `tenma.dev.php`, `tenma build`
- [Debugger and editor setup](debugger.md): `--inspect`, VS Code, Neovim, Zed, Helix, Emacs
- [Language server](language-server.md): `tenma lsp`, providers and semantic tokens for packages
- [Diagnostics](diagnostics.md): problems a running app reports to the editor
- [Reference pages](reference-docs.md): `tenma docs`

Running:

- [Command line and deployment](deployment.md): options, workers, limits, signals, Docker, systemd, Homebrew
- [Configuration and php.ini](configuration.md): PHP settings, extensions, opcache
