---
title: Databases and messages
description: Query Postgres and Redis without blocking, use transactions and the connection pool, and send messages between workers and processes.
order: 50
---

Tenma has asynchronous clients for Postgres and Redis built in. While a query runs, only the fiber of the request that sent it waits; the worker serves other requests meanwhile.

## Postgres

```php
<?php

use Tenma\Db\Postgres;
use Tenma\Http\{Request, Response};

$db = Postgres::connect(getenv('DATABASE_URL') ?: 'postgres://app@localhost/app');

// Startup work that waits runs in a task
Tenma::async(fn () => $db->script(<<<'SQL'
    CREATE TABLE IF NOT EXISTS notes (
        id serial PRIMARY KEY,
        text text NOT NULL,
        created timestamptz NOT NULL DEFAULT now()
    );
SQL));

Tenma::serve(8080, function (Request $request) use ($db) {
    if ($request->method === 'POST') {
        $note = $db->first('INSERT INTO notes (text) VALUES ($1) RETURNING *', [$request->form()['text'] ?? '']);
        return Response::json($note, 201);
    }
    if (preg_match('#^/notes/(\d+)$#', $request->path, $match)) {
        $note = $db->first('SELECT * FROM notes WHERE id = $1', [$match[1]]);
        return $note === null ? new Response("Not found\n", 404) : Response::json($note);
    }
    return Response::json($db->query('SELECT id, text FROM notes ORDER BY id DESC LIMIT 20'));
});
```

[`Postgres`](../reference/Tenma/Db/Postgres.md) has five methods:

| Method | Returns |
|---|---|
| `query($sql, $params)` | all rows, a list of associative arrays |
| `first($sql, $params)` | the first row, or `null` |
| `execute($sql, $params)` | the number of affected rows |
| `script($sql)` | nothing: several statements without parameters (migrations), run as one |
| `transaction(fn (Postgres $tx) => …)` | what the function returns |

Parameters are written `$1`, `$2`, … as in Postgres itself. They are sent as text and the server decides the type, as with PDO: `'42'` fits an `int` column, a date can be a string. Never build SQL from input; pass it as a parameter.

Results come back with JSON types: numbers as int or float, `jsonb` as arrays, `timestamptz`, `uuid` and dates as strings. A `numeric` with more digits than a float holds exactly arrives as a string.

`connect()` itself does not wait; the first query opens the connection. Connections must be established within 10 seconds. TLS for Postgres connections is not supported yet: connect over a private network or a local socket.

### Transactions

```php
<?php

use Tenma\Db\Postgres;

$db = Postgres::connect(getenv('DATABASE_URL') ?: 'postgres://app@localhost/app');

function transfer(Postgres $db, int $from, int $to, int $cents): void
{
    $db->transaction(function (Postgres $tx) use ($from, $to, $cents) {
        $tx->execute('UPDATE accounts SET balance = balance - $1 WHERE id = $2', [$cents, $from]);
        $tx->execute('UPDATE accounts SET balance = balance + $1 WHERE id = $2', [$cents, $to]);
        if ($tx->first('SELECT balance FROM accounts WHERE id = $1', [$from])['balance'] < 0) {
            throw new RuntimeException('not enough money');   // rolls back
        }
    });
}
```

The transaction commits when the function returns and rolls back when it throws; the exception is rethrown. Inside, query through `$tx`, never through the outer `$db`: that needs a second connection, and if every connection of the pool is held by such a transaction, it waits for itself. `COMMIT` and `ROLLBACK` always run to completion, even when the request is cancelled.

### The pool

Each worker keeps a pool per URL, `--db-pool` connections at most. By default the pool is 20 divided by the number of workers, at least 2 and at most 10 per worker: about 20 connections in all, well below Postgres' default `max_connections` of 100. A query that finds every connection busy waits up to 10 seconds, then throws.

After every query, the connection is cleaned before it goes back to the pool (a `ROLLBACK`, then what `DISCARD ALL` does, except dropping prepared statements). `SET ROLE`, `set_config()`, temporary tables, advisory locks or a forgotten `BEGIN` never leak into another request. Session state across several statements therefore only exists inside `transaction()`.

## Redis

```php
<?php

use Tenma\Db\Redis;
use Tenma\Http\{Request, Response};

$redis = Redis::connect(getenv('REDIS_URL') ?: 'redis://127.0.0.1:6379');

Tenma::serve(8080, function (Request $request) use ($redis) {
    $views = $redis->command('INCR', 'views:' . $request->path);
    $redis->set('last-path', $request->path, seconds: 60);
    return Response::json([
        'views' => $views,
        'last' => $redis->get('last-path'),
        'top' => $redis->command('ZREVRANGE', 'scores', 0, 2, 'WITHSCORES'),
    ]);
});
```

[`Redis`](../reference/Tenma/Db/Redis.md) has `get()`, `set($key, $value, $seconds)` and `command($name, ...$args)` for every other command; replies come back as strings, ints, arrays or `null`. The database number goes in the URL (`redis://host:6379/2`).

Each worker has one connection per URL, shared by all its fibers. Therefore `command()` refuses what would change that connection for everyone or hold it: `MULTI`/`EXEC`/`WATCH` (use `EVAL` with a Lua script for atomic steps), `SELECT` (put the database in the URL), `SUBSCRIBE` (use `Tenma::subscribe()` with `--pubsub`, below) and blocking commands like `BLPOP` or `XREAD BLOCK`.

```php
// An atomic "take a ticket if any are left", instead of MULTI/EXEC
$taken = $redis->command('EVAL', <<<'LUA'
    if tonumber(redis.call('GET', KEYS[1]) or '0') > 0 then
        return redis.call('DECR', KEYS[1])
    end
    return -1
LUA, 1, 'tickets');
```

## Messages between workers

`Tenma::publish($channel, $message)` hands a string to every subscription of the channel in every worker of the process, the publishing one included. `Tenma::subscribe($channel, $callback)` calls the callback with each message, in its own fiber, and returns a [`Subscription`](../reference/Tenma/Subscription.md) with `cancel()`.

Workers share no PHP objects, so a message is a string: send what changed (an ID, some JSON) and let each worker update its own state. A typical use is keeping in-memory caches in sync:

```php
<?php

use Tenma\Http\{Request, Response};

$prices = ['coffee' => 300];

// Made at startup: lasts as long as the worker
Tenma::subscribe('prices', function (string $message) use (&$prices) {
    $prices = json_decode($message, true);
});

Tenma::serve(8080, function (Request $request) use (&$prices) {
    if ($request->method === 'POST') {
        Tenma::publish('prices', json_encode([...$prices, ...$request->form()]));   // every worker updates
        return Response::redirect('/', 303);
    }
    return Response::json($prices);
});
```

Run it with `tenma --workers 4 app.php`, POST a new price, and every worker answers with it. A subscription made in a request ends with the request (for streams and WebSockets, with the connection). A worker that is starting or restarting misses what is published meanwhile. A subscription keeps a script without a server alive.

### Across processes

Several processes of the same app (on one machine or behind a load balancer) start with `--pubsub`:

```bash
tenma --pubsub redis://cache.internal:6379 app.php
```

Messages then go through Redis `PUBLISH` and `SUBSCRIBE` on channels of the same name, and every worker of every process hears them. The PHP code stays the same. If Redis goes away, Tenma reports it once and reconnects every second; messages published meanwhile go out after the reconnect, and subscriptions in other processes miss what was published while they were cut off.

## MySQL, SQLite and PDO

PDO (MySQL, Postgres, SQLite), mysqli and sqlite3 are built in and work as usual, but they block: while a PDO query runs, no other request of that worker runs. They are fine for scripts, for classic mode and for SQLite with short queries; for request handlers under load prefer `Tenma\Db\Postgres`. An asynchronous MySQL client is not available yet.

See also: [`Postgres`](../reference/Tenma/Db/Postgres.md), [`Redis`](../reference/Tenma/Db/Redis.md), [`Tenma::publish()`](../reference/Tenma.md#publish), [`Tenma::subscribe()`](../reference/Tenma.md#subscribe), [`Subscription`](../reference/Tenma/Subscription.md).
