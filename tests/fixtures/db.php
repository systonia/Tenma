<?php
// For tests/http.rs: Postgres and Redis asynchronously. Needs a running Postgres (DATABASE_URL) and Redis (REDIS_URL).
use Tenma\Db\{Postgres, Redis};
use Tenma\Http\{Request, Response};


$db = Postgres::connect(getenv('DATABASE_URL'));
$redis = Redis::connect(getenv('REDIS_URL'));
$table = 'tenma_test_' . getmypid();

Tenma::serve((int) getenv('PORT'), function (Request $request) use ($db, $redis, $table): Response {
    return Response::json(match ($request->path) {
        '/setup' => $db->execute("CREATE TABLE $table (id serial PRIMARY KEY, name text NOT NULL, amount int, price numeric, created timestamptz DEFAULT now(), data jsonb)"),
        // Numbers as strings and a date as a string: Postgres infers the type, like with PDO
        '/insert' => $db->first("INSERT INTO $table (name, amount, price, data) VALUES ($1, $2, $3, $4) RETURNING *", [$request->query['name'], '42', 9.99, json_encode(['tags' => ['a', 'b']])]),
        '/list' => $db->query("SELECT id, name, amount FROM $table WHERE created < $1 ORDER BY id", ['2999-01-01']),
        '/rollback' => (function () use ($db, $table) {
            try {
                $db->transaction(function (Postgres $tx) use ($table) {
                    $tx->execute("INSERT INTO $table (name) VALUES ('discarded')");
                    throw new RuntimeException('on purpose');
                });
            } catch (RuntimeException) {
            }
            return $db->first("SELECT count(*) AS n FROM $table WHERE name = 'discarded'");
        })(),
        '/commit' => $db->transaction(fn (Postgres $tx) => $tx->execute("UPDATE $table SET amount = amount + 1")),
        // Several statements in one call run as one: the failing second one takes the first with it
        '/script-fails' => (function () use ($db, $table) {
            try {
                $db->script("INSERT INTO $table (name) VALUES ('from-script'); INSERT INTO does_not_exist VALUES (1);");
            } catch (RuntimeException $error) {
                return ['error' => str_contains($error->getMessage(), 'does_not_exist'), 'kept' => $db->first("SELECT count(*)::int AS n FROM $table WHERE name = 'from-script'")];
            }
            return ['error' => false];
        })(),
        // Several statements in one call, in a transaction: a migration file
        '/script' => $db->transaction(function (Postgres $tx) {
            $tx->script("CREATE TEMP TABLE script_test (n int);\nINSERT INTO script_test VALUES (1), (2);\n-- a comment; with a semicolon\nINSERT INTO script_test VALUES (3);");
            return $tx->first('SELECT count(*)::int AS n FROM script_test');
        }),
        '/slow' => $db->first('SELECT pg_sleep(0.5)::text AS slept'),
        '/error' => (function () use ($db) {
            try {
                return $db->query('SELECT * FROM does_not_exist');
            } catch (RuntimeException $error) {
                return ['error' => $error->getMessage()];
            }
        })(),
        '/redis' => [$redis->set("$table:key", 'value', 60), $redis->get("$table:key"), $redis->command('INCR', "$table:counter"), $redis->command('DEL', "$table:key", "$table:counter")],
        '/drop' => $db->execute("DROP TABLE $table"),
        // What one request changes on the connection, the next one must not see
        // Session state and a transaction left open; afterwards the connection must be clean
        '/taint' => [$db->execute("SET application_name = 'tenant_a'"), $db->execute('BEGIN'), $db->execute("SET LOCAL application_name = 'in_transaction'")],
        // exit() in the middle of a transaction: connection and pool slot must be released
        '/exit-tx' => $db->transaction(function (Postgres $tx) {
            $tx->execute('SELECT 1');
            exit;
        }),
        // Kills its own Redis connection, like a Redis restart would
        '/redis-kill' => $redis->command('CLIENT', 'KILL', 'ID', (string) $redis->command('CLIENT', 'ID')),
        '/redis-ping' => $redis->command('PING'),
        // A comment at the end and the order from ORDER BY
        '/comment' => array_column($db->query('SELECT n FROM generate_series(1, 5) n ORDER BY n DESC -- newest first'), 'n'),
        // all() in a transaction: the error cancels the running query, the rollback succeeds
        '/all-tx' => (function () use ($db) {
            try {
                $db->transaction(fn (Postgres $tx) => Tenma::all(
                    fn () => $tx->execute('SELECT pg_sleep(2)'),
                    function () {
                        Tenma::sleep(0.05);
                        throw new DomainException('on purpose');
                    },
                ));
            } catch (DomainException $error) {
                return 'caught: ' . $error->getMessage();
            }
        })(),
        // Cancellation in the middle of a transaction: the rollback in the catch must still be allowed to run
        '/cancel-tx' => (function () use ($db) {
            $task = Tenma::async(fn () => $db->transaction(function (Postgres $tx) {
                $tx->execute('SELECT 1');
                Tenma::sleep(5);
            }));
            Tenma::sleep(0.05);
            $task->cancel();
            try {
                $task->await();
            } catch (Tenma\Cancelled) {
                return 'cancelled';
            }
        })(),
        '/whoami' => $db->first("SELECT current_setting('application_name') AS name"),
        '/backend' => $db->first('SELECT pg_backend_pid() AS pid'),
        // Parameters of the app's own types: the driver looks each one up the first time it meets it
        '/types' => $db->script("DROP TYPE IF EXISTS {$table}_mood, {$table}_size; CREATE TYPE {$table}_mood AS ENUM ('happy'); CREATE TYPE {$table}_size AS ENUM ('big')"),
        '/mood' => $db->first("SELECT \$1::{$table}_mood::text AS value", ['happy']),
        '/size' => $db->first("SELECT \$1::{$table}_size::text AS value", ['big']),
        '/types-drop' => $db->script("DROP TYPE {$table}_mood, {$table}_size"),
        default => 'ok',
    });
});
