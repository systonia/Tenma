---
title: Record and replay
description: Record what a worker receives with --record and run it again with tenma replay, also step by step backwards in the debugger.
order: 325
---

A request that failed in production can run again on a laptop, exactly as it ran then. tenma records
everything a worker gets from outside: every request, the result of every op (a timer, a fetch, a
query, a file, a message), the moment a client leaves, and the values that `time()`, `random_int()`
and their kin returned. `tenma replay` plays that journal into a fresh interpreter. No op runs for
real; PHP gets the recorded answers in the recorded order.

## Record

```bash
tenma --record traces/ app.php
```

Every worker writes one file per interpreter life into `traces/`: `<pid>-<worker>-<start>.trace`,
closed when the worker restarts (`--max-requests`, a fatal error) or tenma stops. A replay starts at
the worker's start, because a request depends on what earlier requests left in memory. Keep lives
short with `--max-requests` while recording.

| Option                       | Default | Does                                                                                   |
| ---------------------------- | ------- | -------------------------------------------------------------------------------------- |
| `--record DIR`               |         | every worker writes its journal there                                                  |
| `--record-on error`          | always  | keeps a journal only when a request answered 5xx or PHP failed; the others are deleted |
| `--record-max-body SIZE`     | `1m`    | request bodies and op results above this are cut; the request is marked not replayable |
| `--record-redact HEADER,...` | none    | replaces these request headers with `[redacted]`                                       |

A journal holds request headers, cookies and query results: it is as secret as the traffic itself.
Keep the directory out of the repository and of reach.

## Replay

```bash
tenma replay traces/4711-0-1792569255382.trace
tenma replay traces/4711-0-1792569255382.trace --check
tenma replay traces/4711-0-1792569255382.trace --until 3
```

One worker runs the recorded script with the recorded arguments, environment and working directory,
without opening a port. Each request is injected when its turn comes. `--until N` stops after request
N. `--check` compares every response with the recorded one (headers except `date`, status, body) and
ends with status 1 if one differs: recorded traffic becomes a regression test against new code.

If PHP asks for something else than the journal has next (another op, another function, a `time()`
call that was not there), the code differs from the recorded code. The replay stops and says so:

```
tenma replay: diverged: the journal has op 2 (sleep 50 ms), but PHP started sleep 70 ms
```

The journal ends with the loaded files and their hashes; a replay names the files that changed. That
is a hint, not an error: replaying old traffic against new code is the point of `--check`.

## In the debugger

```bash
tenma --inspect-wait replay traces/4711-0-1792569255382.trace
```

The debugger works as always, and gains two commands: step back and reverse continue. tenma restarts
the replay from the worker's start and runs it to the statement before, in the same request, or to the
last place it stopped before. Without real I/O that takes as long as the computation, usually well
under a second. The editor shows the usual stepping buttons for it.

## What a replay covers

| Repeats exactly                                                                                                                                                                                  | Does not                                                                                           |
| ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | -------------------------------------------------------------------------------------------------- |
| requests, responses, streams, WebSocket messages, a client leaving                                                                                                                               | PHP's own sockets (mysqlnd, `fsockopen()`): they connect for real, or fail                         |
| `Task::delay()`, timers, `Http\Client`, `Data\Postgres`, `Data\Redis`, PDO with `pgsql:`, files, channels                                                                                        | `sleep()`, `usleep()`, curl, ext/pgsql: they run for real; the journal notes that they were called |
| `time()`, `microtime()`, `hrtime()`, `date()`, `strtotime()`, `date_create()`, `random_int()`, `random_bytes()`, `mt_rand()`, `rand()`, `uniqid()`, `lcg_value()`, `getmypid()`, `gethostname()` | `new DateTime()` and anything else that reads the clock without one of those functions             |
| the environment, `$argv`, the working directory                                                                                                                                                  | `spl_object_id()` and other values that depend on allocation, unless the code is the same          |

A request whose body or result was cut by `--record-max-body` cannot be replayed exactly; the journal
says which.

## See also

- [Debugger](debugger.md)
- [Command line](command-line.md)
