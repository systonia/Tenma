---
title: "Threading"
description: "The classes, interfaces, traits and enums in Threading."
order: 200
generated: true
---

## Types

- [`AsyncLocal`](AsyncLocal.md) · final class: A value that travels along with asynchronous work, for example the request ID for log lines.
- [`Cancelled`](Cancelled.md) · class: The task or request was cancelled, for example because a sibling in all() failed or exit() ran.
- [`Task`](Task.md) · final class: Work that runs in its own Fiber while the caller carries on; await() fetches its result.
- [`Timer`](Timer.md) · final class: A timer from after() or every(); cancel() stops it.
