---
title: Postgres, Redis and pub/sub
description: Query Postgres and Redis without blocking, use transactions and the connection pool, and send messages between workers and processes.
order: 260
---

Tenma has asynchronous clients for Postgres and Redis built in. While a query runs, only the fiber that sent it waits, and the worker serves other requests. `Tenma::publish()` and `Tenma::subscribe()` send messages between workers, and with `--pubsub` between processes.

| Method                                                                       | Does                                                                                  |
| ---------------------------------------------------------------------------- | ------------------------------------------------------------------------------------- |
| [`Postgres::connect($url)`](../reference/Tenma/Db/Postgres.md#connect)       | Returns a client for `postgres://user:password@host:5432/database`.                   |
| [`$db->query($sql, $params)`](../reference/Tenma/Db/Postgres.md#query)       | Returns all rows as a list of associative arrays.                                     |
| [`$db->first($sql, $params)`](../reference/Tenma/Db/Postgres.md#first)       | Returns the first row, or `null`.                                                     |
| [`$db->execute($sql, $params)`](../reference/Tenma/Db/Postgres.md#execute)   | Runs a statement without a result and returns the number of affected rows.            |
| [`$db->script($sql)`](../reference/Tenma/Db/Postgres.md#script)              | Runs several statements without parameters as one.                                    |
| [`$db->transaction($work)`](../reference/Tenma/Db/Postgres.md#transaction)   | Runs `$work($tx)` in a transaction and returns its result.                            |
| [`Redis::connect($url)`](../reference/Tenma/Db/Redis.md#connect)             | Returns a client for `redis://host:6379/0` (the default is `redis://127.0.0.1:6379`). |
| [`$redis->get($key)`](../reference/Tenma/Db/Redis.md#get)                    | Returns the value, or `null`.                                                         |
| [`$redis->set($key, $value, $seconds)`](../reference/Tenma/Db/Redis.md#set)  | Sets the value, with an expiry in seconds if given.                                   |
| [`$redis->command($name, ...$args)`](../reference/Tenma/Db/Redis.md#command) | Runs any other Redis command and returns its reply.                                   |
| [`Tenma::publish($channel, $message)`](../reference/Tenma.md#publish)        | Sends a string to every subscription of the channel.                                  |
| [`Tenma::subscribe($channel, $callback)`](../reference/Tenma.md#subscribe)   | Calls the callback with every message and returns a `Subscription`.                   |
| [`$subscription->cancel()`](../reference/Tenma/Subscription.md)              | Stops the subscription.                                                               |

## Postgres

```php
<?php

use Tenma\Db\Postgres;
use Tenma\Http\{Request, Response};

$db = Postgres::connect(getenv('DATABASE_URL') ?: 'postgres://localhost/example');

// Queries wait, so startup work runs in a task
Tenma::async(fn () => $db->script(<<<'SQL'
    CREATE TABLE IF NOT EXISTS users (
        id serial PRIMARY KEY,
        name text NOT NULL,
        settings jsonb NOT NULL DEFAULT '{"theme": "light"}'
    );
    SQL));

Tenma::serve(8080, function (Request $request) use ($db) {
    if ($request->method === 'POST') {
        $user = $db->first(
            'INSERT INTO users (name) VALUES ($1) RETURNING *',
            [$request->form()['name'] ?? ''],
        );
        return Response::json($user, 201);
    }

    if (preg_match('#^/users/(\d+)$#', $request->path, $match)) {
        $user = $db->first('SELECT * FROM users WHERE id = $1', [$match[1]]);
        return $user === null ? new Response("Not found\n", 404) : Response::json($user);
    }

    return Response::json($db->query('SELECT id, name FROM users ORDER BY id'));
});
```

`curl -d name=Ada localhost:8080/users` adds a user. `curl localhost:8080/users/1` reads it back, with `settings` as an array: `jsonb` arrives decoded.

### Parameters and results

Parameters are written `$1`, `$2`, … as in Postgres itself. They are sent as text, and the server decides the type. So `'42'` fits an `int` column, and a date can be a string. Never build SQL from input: pass it as a parameter.

Results come back with JSON types:

| Postgres                     | PHP                                                    |
| ---------------------------- | ------------------------------------------------------ |
| integers, `float8`           | `int`, `float`                                         |
| `numeric`                    | `int` or `float`, a string when a float would round it |
| `boolean`                    | `bool`                                                 |
| `json`, `jsonb`              | arrays                                                 |
| `timestamptz`, dates, `uuid` | strings                                                |
| `NULL`                       | `null`                                                 |

`query()` and `first()` take statements that return rows: `SELECT`, `VALUES`, `INSERT … RETURNING` and a data-modifying `WITH`. The rows come back in the order of the statement. `SHOW`, `EXPLAIN` and `FETCH` work too, but take no parameters and return every value as a string.

## Transactions

```php
<?php

use Tenma\Db\Postgres;

$db = Postgres::connect(getenv('DATABASE_URL') ?: 'postgres://localhost/example');

Tenma::async(function () use ($db) {
    $db->script(<<<'SQL'
        CREATE TABLE IF NOT EXISTS accounts (
            id int PRIMARY KEY,
            balance int NOT NULL CHECK (balance >= 0)
        );
        INSERT INTO accounts VALUES (1, 100), (2, 0) ON CONFLICT (id) DO NOTHING;
        SQL);

    $transfer = function (int $from, int $to, int $amount) use ($db) {
        return $db->transaction(function (Postgres $tx) use ($from, $to, $amount) {
            $tx->execute(
                'UPDATE accounts SET balance = balance + $1 WHERE id = $2',
                [$amount, $to],
            );
            $tx->execute(
                'UPDATE accounts SET balance = balance - $1 WHERE id = $2',
                [$amount, $from],
            );
            return $tx->query('SELECT * FROM accounts ORDER BY id');
        });
    };

    print_r($transfer(1, 2, 60));   // balances 40 and 60

    try {
        $transfer(1, 2, 60);   // only 40 left: the CHECK fails, both updates roll back
    } catch (RuntimeException $e) {
        echo $e->getMessage(), "\n";
    }
});
```

The transaction commits when the function returns. It rolls back when the function throws, and the exception is rethrown.

Inside the function, query through `$tx`, never through the outer `$db`. The outer client needs a second connection from the pool. If the transactions hold every connection, the query waits for itself and fails after 10 seconds.

## The pool

Each worker keeps a pool per URL, with at most `--db-pool` connections:

```bash
tenma --workers 4 --db-pool 5 app.php
```

The default is 20 divided by the number of workers, at least 2 and at most 10 per worker. That is about 20 connections in all, well below the Postgres default `max_connections` of 100.

After every query, Tenma cleans the connection before it goes back to the pool. It runs a `ROLLBACK`, then what `DISCARD ALL` does, except dropping prepared statements. So `SET`, `SET ROLE`, `set_config()`, temporary tables, advisory locks and a forgotten `BEGIN` never leak into another request. Session state across several statements only exists inside `transaction()`.

## Migrations

`script()` runs a migration file as one batch. The script below applies the files of `migrations/` in name order, each one once. All pending files run in one transaction, so a failing file leaves the database as it was. The advisory lock makes a second start wait for the first.

```php
<?php

use Tenma\Db\Postgres;
use Tenma\Fs;

$db = Postgres::connect(getenv('DATABASE_URL') ?: 'postgres://localhost/example');
$dir = __DIR__ . '/migrations';

Tenma::async(function () use ($db, $dir) {
    $ran = $db->transaction(function (Postgres $tx) use ($dir) {
        // A second start waits here until the first one commits
        $tx->execute('SELECT pg_advisory_xact_lock(1)');

        $tx->script('CREATE TABLE IF NOT EXISTS schema_migrations (name text PRIMARY KEY)');
        $applied = array_column($tx->query('SELECT name FROM schema_migrations'), 'name');

        $ran = [];
        foreach (Fs::readDir($dir) as $name) {
            if (in_array($name, $applied, true)) {
                continue;
            }
            $tx->script(Fs::readFile("$dir/$name"));
            $tx->execute('INSERT INTO schema_migrations (name) VALUES ($1)', [$name]);
            $ran[] = $name;
        }

        return $ran;
    });

    echo 'Applied: ', implode(', ', $ran) ?: 'nothing', "\n";
});
```

Run it with `tenma migrate.php`, not `php`: the Postgres client needs Tenma's event loop. Locks taken with `pg_advisory_xact_lock()` end with the transaction, so they fit the pool.

A migration file must not contain `COMMIT` or `ROLLBACK`. Statements that refuse to run in a transaction (`CREATE INDEX CONCURRENTLY`, `VACUUM`, `CREATE DATABASE`) do not work in `script()`.

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
        'ttl' => $redis->command('TTL', 'last-path'),
    ]);
});
```

Replies come back as strings, ints, arrays or `null`. The database number goes in the URL (`redis://host:6379/2`).

### Atomic steps

Each worker has one Redis connection per URL, shared by all its fibers. `MULTI` and `EXEC` would mix the commands of every fiber, so `command()` refuses them. Use `EVAL` with a Lua script instead:

```php
<?php

use Tenma\Db\Redis;

$redis = Redis::connect('redis://127.0.0.1:6379');

Tenma::async(function () use ($redis) {
    $redis->set('stock', '1');

    // Take one if any are left, in one atomic step
    $take = <<<'LUA'
        if tonumber(redis.call('GET', KEYS[1]) or '0') > 0 then
            return redis.call('DECR', KEYS[1])
        end
        return -1
        LUA;

    echo $redis->command('EVAL', $take, 1, 'stock'), "\n";   // 0
    echo $redis->command('EVAL', $take, 1, 'stock'), "\n";   // -1
});
```

## Messages between workers

`Tenma::publish($channel, $message)` hands a string to every subscription of the channel. That includes every worker of the process, the publishing one too. `Tenma::subscribe($channel, $callback)` calls the callback with each message, in its own fiber.

Workers share no PHP objects, so a message is a string. Send what changed (an ID, some JSON) and let each worker update its own state. A typical use keeps in-memory caches in sync:

```php
<?php

use Tenma\Http\{Request, Response};

$settings = ['theme' => 'light'];

// Made at startup: lasts as long as the worker
Tenma::subscribe('settings', function (string $message) use (&$settings) {
    $settings = json_decode($message, true);
});

Tenma::serve(8080, function (Request $request) use (&$settings) {
    if ($request->method === 'POST') {
        // Every worker updates its own copy
        Tenma::publish('settings', json_encode([...$settings, ...$request->form()]));
        return new Response("published\n", 202);
    }

    return Response::json($settings);
});
```

Run it with `tenma --workers 4 app.php` and send `curl -d theme=dark localhost:8080`. Every worker then answers with the new theme.

A subscription ends with `cancel()`:

```php
<?php

$received = 0;

$subscription = Tenma::subscribe('jobs', function (string $message) use (&$received, &$subscription) {
    echo "got $message\n";

    if (++$received === 2) {
        $subscription->cancel();   // without this, the subscription keeps the script running
    }
});

Tenma::publish('jobs', 'first');
Tenma::publish('jobs', 'second');
```

### Across processes

Several processes of the same app (on one machine or behind a load balancer) start with `--pubsub`:

```bash
tenma --pubsub redis://cache.internal:6379 app.php
```

Messages then go through Redis `PUBLISH` and `SUBSCRIBE`, on channels of the same name. Every worker of every process hears them. The PHP code stays the same.

## PDO, mysqli and sqlite3

PDO (MySQL, Postgres, SQLite), mysqli and sqlite3 are built in and work as usual. They block: while a PDO query runs, no other request of that worker runs. They fit scripts, [classic mode](classic-mode.md) and SQLite with short queries. For request handlers under load, use `Tenma\Db\Postgres`. An asynchronous MySQL client is not available yet.

## Behaviour

Postgres:

- Every method needs a fiber: a handler, a task or a timer. At script startup, wrap queries in `Tenma::async()`.
- `connect()` does not wait. The first query opens the connection, which must be established within 10 seconds.
- Database errors throw a `RuntimeException` with the server's message.
- A query that finds every connection of the pool busy waits up to 10 seconds, then throws.
- A transaction runs one query at a time. A second query on the same `$tx` while one runs throws.
- `COMMIT` and `ROLLBACK` always run to completion, even when the request is cancelled.
- `script()` runs as one: a failing statement undoes the ones before it. Inside `transaction()`, it is part of that transaction.
- TLS for Postgres connections is not supported yet. Connect over a private network or a local socket.

Redis:

- `command()` throws a `LogicException` for commands that would change the shared connection for every fiber or hold it: `MULTI`, `EXEC`, `DISCARD`, `WATCH`, `SELECT`, `SWAPDB`, `AUTH`, `HELLO`, `SUBSCRIBE` and its variants, `MONITOR`, `WAIT`, `CLIENT REPLY`, and blocking commands such as `BLPOP`, `BRPOP` or `XREAD … BLOCK`.
- Put the database in the URL instead of `SELECT`. Use `Tenma::subscribe()` with `--pubsub` instead of `SUBSCRIBE`.
- Connecting times out after 10 seconds, a reply after 30 seconds.
- After a connection error, the next command connects again.

Messages:

- `publish()` does not wait. A worker that has not subscribed yet (still starting or restarting) misses the message.
- A subscription made at startup lasts as long as the worker. One made in a request ends with the request, for streams and WebSockets with the connection.
- A subscription keeps a script without a server alive until `cancel()`. Callbacks already running finish.
- With `--pubsub`, a lost Redis connection is reported once on stderr, and Tenma reconnects every second. Messages published meanwhile go out after the reconnect. Other processes miss what was published while they were cut off.
