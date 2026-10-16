---
title: "Tenma"
description: "The classes, interfaces, traits and enums in Tenma."
order: 200
generated: true
---

## Namespaces

- [`Tenma\Db`](Db/index.md)
- [`Tenma\Http`](Http/index.md)
- [`Tenma\Lsp`](Lsp/index.md)

## Types

- [`AsyncLocal`](AsyncLocal.md) · final class: A value that travels along with asynchronous work, for example the request ID for log lines.
- [`Cancelled`](Cancelled.md) · class: The task or request was cancelled, for example because a sibling in all() failed or exit() ran.
- [`Fs`](Fs.md) · final class: Asynchronous file system functions: only the Fiber waits while the work runs on a background thread.
- [`Hook`](Hook.md) · final readonly class: A build step for `tenma --watch` and `tenma build`, returned in a list from tenma.dev.php next to the script.
- [`Subscription`](Subscription.md) · final class: A subscription from Tenma::subscribe().
- [`Task`](Task.md) · final class: Work that runs in its own Fiber while the caller carries on; await() fetches its result.
- [`Timer`](Timer.md) · final class: A timer from setTimeout() or setInterval().
