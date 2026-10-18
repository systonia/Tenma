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

$db = Postgres::connect(getenv('DATABASE_URL') ?: 'postgres://localhost/bakery');

// Startup work that waits runs in a task
Tenma::async(fn () => $db->script(<<<'SQL'
    CREATE TABLE IF NOT EXISTS products (
        id serial PRIMARY KEY,
        slug text NOT NULL UNIQUE,
        name jsonb NOT NULL,
        price_cents integer NOT NULL CHECK (price_cents > 0),
        stock integer NOT NULL CHECK (stock >= 0)
    );
SQL));

Tenma::serve(8080, function (Request $request) use ($db) {
    if ($request->method === 'POST') {
        $form = $request->form();
        $product = $db->first(
            'INSERT INTO products (slug, name, price_cents, stock) VALUES ($1, $2, $3, $4) RETURNING *',
            [$form['slug'] ?? '', json_encode(['en' => $form['name'] ?? '']), $form['price'] ?? 0, $form['stock'] ?? 0],
        );
        return Response::json($product, 201);
    }
    if (preg_match('#^/products/([a-z-]+)$#', $request->path, $match)) {
        $product = $db->first('SELECT * FROM products WHERE slug = $1', [$match[1]]);
        return $product === null ? new Response("Not found\n", 404) : Response::json($product);
    }
    return Response::json($db->query('SELECT slug, name ->> $1 AS name, price_cents FROM products ORDER BY id', ['en']));
});
```

`curl -d slug=croissant -d name=Croissant -d price=180 -d stock=24 localhost:8080` adds a product, `curl localhost:8080/products/croissant` reads it back, with `name` as an array: `jsonb` arrives decoded.

The bakery keeps every statement in a repository class that gets the pool injected. A method waits only for its own query:

```php
// from src/Repositories/Products.php of the bakery
public function bySlug(string $slug): ?Product
{
    $row = $this->db->first('SELECT ' . self::COLUMNS . ' FROM products p WHERE p.slug = $1', [$slug]);
    return $row === null ? null : Product::of(new Row($row));
}
```

[`Postgres`](../reference/Tenma/Db/Postgres.md) has five methods:

| Method                                | Returns                                                                 |
| ------------------------------------- | ----------------------------------------------------------------------- |
| `query($sql, $params)`                | all rows, a list of associative arrays                                  |
| `first($sql, $params)`                | the first row, or `null`                                                |
| `execute($sql, $params)`              | the number of affected rows                                             |
| `script($sql)`                        | nothing: several statements without parameters (migrations), run as one |
| `transaction(fn (Postgres $tx) => …)` | what the function returns                                               |

Parameters are written `$1`, `$2`, … as in Postgres itself. They are sent as text and the server decides the type, as with PDO: `'42'` fits an `int` column, a date can be a string. Never build SQL from input; pass it as a parameter.

Results come back with JSON types: numbers as int or float, `jsonb` as arrays, `timestamptz`, `uuid` and dates as strings. A `numeric` with more digits than a float holds exactly arrives as a string.

`connect()` itself does not wait; the first query opens the connection. Connections must be established within 10 seconds. TLS for Postgres connections is not supported yet: connect over a private network or a local socket.

### Transactions

A checkout reserves the stock of every product in the order, or of none: if one is short, nothing is reserved. From the bakery, shortened:

```php
// from src/Repositories/Orders.php of the bakery, place()
$id = $this->db->transaction(function (Postgres $tx) use ($quantities, $name, $email, $pickupDate, $locale): int {
    $id = ($tx->first(
        'INSERT INTO orders (code, customer_name, email, pickup_date, locale) VALUES ($1, $2, $3, $4, $5) RETURNING id',
        [bin2hex(random_bytes(5)), $name, $email, $pickupDate, $locale],
    ) ?? throw new DomainException('The order was not stored'))['id'];
    ksort($quantities);   // the same order of row locks in every transaction: no deadlocks between two checkouts
    foreach ($quantities as $product => $quantity) {
        $reserved = $tx->first(
            'UPDATE products SET stock = stock - $1 WHERE id = $2 AND stock >= $1 RETURNING name ->> $3 AS name, price_cents',
            [$quantity, $product, $locale],
        ) ?? throw new OutOfStock("#$product");   // rolls back: nothing is reserved
        $tx->execute(
            'INSERT INTO order_lines (order_id, product_id, name, price_cents, quantity) VALUES ($1, $2, $3, $4, $5)',
            [$id, $product, $reserved['name'], $reserved['price_cents'], $quantity],
        );
    }
    return $id;
});
```

The `UPDATE … WHERE stock >= $1` also decides who gets the last croissant: the row lock lets one checkout through and the second finds the stock too low.

The transaction commits when the function returns and rolls back when it throws; the exception is rethrown. Inside, query through `$tx`, never through the outer `$db`: that needs a second connection, and if every connection of the pool is held by such a transaction, it waits for itself. `COMMIT` and `ROLLBACK` always run to completion, even when the request is cancelled.

### The pool

Each worker keeps a pool per URL, `--db-pool` connections at most. By default the pool is 20 divided by the number of workers, at least 2 and at most 10 per worker: about 20 connections in all, well below Postgres' default `max_connections` of 100. A query that finds every connection busy waits up to 10 seconds, then throws.

After every query, the connection is cleaned before it goes back to the pool (a `ROLLBACK`, then what `DISCARD ALL` does, except dropping prepared statements). `SET ROLE`, `set_config()`, temporary tables, advisory locks or a forgotten `BEGIN` never leak into another request. Session state across several statements therefore only exists inside `transaction()`.

### Migrations

`script()` runs a migration file as one batch. The bakery applies `database/migrations/*.sql` in name order, each file once, as a CLI task under Tenma, `tenma index.php db:migrate`. All pending files run in one transaction, so a failing one leaves the database as it was, and an advisory lock lets two starts wait for each other:

```php
// from src/Tasks/DatabaseTasks.php of the bakery, migrate()
$ran = $this->db->transaction(function (Postgres $tx): array {
    $tx->execute('SELECT pg_advisory_xact_lock($1)', [self::LOCK]);
    $tx->script('CREATE TABLE IF NOT EXISTS schema_migrations (name text PRIMARY KEY, applied_at timestamptz NOT NULL DEFAULT now())');
    $applied = array_column($tx->query('SELECT name FROM schema_migrations'), 'name');
    $files = glob(self::DIRECTORY . '/migrations/*.sql') ?: [];
    sort($files);
    $ran = [];
    foreach ($files as $file) {
        if (!in_array(basename($file), $applied, true)) {
            $tx->script(\Tenma\Fs::readFile($file));
            $tx->execute('INSERT INTO schema_migrations (name) VALUES ($1)', [basename($file)]);
            $ran[] = basename($file);
        }
    }
    return $ran;
});
```

The task runs under `tenma`, not `php`, because the Postgres client needs Tenma's event loop. Advisory locks taken with `pg_advisory_xact_lock()` end with the transaction, so they fit the pool.

### One statement for several workers

Every worker runs the same timers, so a background job may run in several workers at the same moment. A job written as one statement stays correct: the bakery cancels orders nobody confirmed and gives their stock back in one `WITH`, and the row locks of the `UPDATE` make the second worker find those orders cancelled already:

```php
// from src/Repositories/Orders.php of the bakery, expireUnconfirmed()
return count($this->db->query(<<<'SQL'
    WITH expired AS (
        UPDATE orders SET status = 'cancelled', updated_at = now()
        WHERE status = 'pending' AND created_at < now() - make_interval(mins => $1)
        RETURNING id
    ), restocked AS (
        UPDATE products p SET stock = p.stock + freed.quantity
        FROM (SELECT product_id, sum(quantity) AS quantity FROM order_lines WHERE order_id IN (SELECT id FROM expired) GROUP BY product_id) AS freed
        WHERE p.id = freed.product_id
    )
    SELECT id FROM expired
    SQL, [$minutes]));
```

`query()` runs a statement with its own data-modifying `WITH` as it is and returns the rows of its final `SELECT`.

## Redis

```php
<?php

use Tenma\Db\Redis;
use Tenma\Http\{Request, Response};

$redis = Redis::connect(getenv('REDIS_URL') ?: 'redis://127.0.0.1:6379');

Tenma::serve(8080, function (Request $request) use ($redis) {
    $views = $redis->command('INCR', 'views:' . $request->path);
    $redis->set('last-viewed', $request->path, seconds: 60);
    $redis->command('ZINCRBY', 'popular', 1, $request->path);
    return Response::json([
        'views' => $views,
        'last' => $redis->get('last-viewed'),
        'popular' => $redis->command('ZREVRANGE', 'popular', 0, 2, 'WITHSCORES'),   // the three most viewed products
    ]);
});
```

[`Redis`](../reference/Tenma/Db/Redis.md) has `get()`, `set($key, $value, $seconds)` and `command($name, ...$args)` for every other command; replies come back as strings, ints, arrays or `null`. The database number goes in the URL (`redis://host:6379/2`).

Each worker has one connection per URL, shared by all its fibers. Therefore `command()` refuses what would change that connection for everyone or hold it: `MULTI`/`EXEC`/`WATCH` (use `EVAL` with a Lua script for atomic steps), `SELECT` (put the database in the URL), `SUBSCRIBE` (use `Tenma::subscribe()` with `--pubsub`, below) and blocking commands like `BLPOP` or `XREAD BLOCK`.

```php
// An atomic "take a croissant if any are left", instead of MULTI/EXEC
$left = $redis->command('EVAL', <<<'LUA'
    if tonumber(redis.call('GET', KEYS[1]) or '0') > 0 then
        return redis.call('DECR', KEYS[1])
    end
    return -1
LUA, 1, 'stock:croissant');
```

## Messages between workers

`Tenma::publish($channel, $message)` hands a string to every subscription of the channel in every worker of the process, the publishing one included. `Tenma::subscribe($channel, $callback)` calls the callback with each message, in its own fiber, and returns a [`Subscription`](../reference/Tenma/Subscription.md) with `cancel()`.

Workers share no PHP objects, so a message is a string: send what changed (an ID, some JSON) and let each worker update its own state. A typical use is keeping in-memory caches in sync:

```php
<?php

use Tenma\Http\{Request, Response};

$prices = ['croissant' => 180, 'pretzel' => 95];   // cents

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

Run it with `tenma --workers 4 app.php`, POST a new price (`curl -d croissant=190 localhost:8080`), and every worker answers with it.

The bakery's order board works this way across `tenma --workers 2`: when the staff move an order on, Waypoint Live's `Broadcast::changed()` publishes on one channel, and each worker renders its own open tabs again, reading the order from Postgres. The message is empty: what changed is in the database. A subscription made in a request ends with the request (for streams and WebSockets, with the connection). A worker that is starting or restarting misses what is published meanwhile. A subscription keeps a script without a server alive.

### Across processes

Several processes of the same app (on one machine or behind a load balancer) start with `--pubsub`:

```bash
tenma --pubsub redis://cache.internal:6379 app.php
```

Messages then go through Redis `PUBLISH` and `SUBSCRIBE` on channels of the same name, and every worker of every process hears them. The PHP code stays the same. If Redis goes away, Tenma reports it once and reconnects every second; messages published meanwhile go out after the reconnect, and subscriptions in other processes miss what was published while they were cut off.

## MySQL, SQLite and PDO

PDO (MySQL, Postgres, SQLite), mysqli and sqlite3 are built in and work as usual, but they block: while a PDO query runs, no other request of that worker runs. They are fine for scripts, for classic mode and for SQLite with short queries; for request handlers under load prefer `Tenma\Db\Postgres`. An asynchronous MySQL client is not available yet.

See also: [`Postgres`](../reference/Tenma/Db/Postgres.md), [`Redis`](../reference/Tenma/Db/Redis.md), [`Tenma::publish()`](../reference/Tenma.md#publish), [`Tenma::subscribe()`](../reference/Tenma.md#subscribe), [`Subscription`](../reference/Tenma/Subscription.md).
