<?php
// For tests/http.rs: a handler whose answer depends on the clock, randomness, a timer, a fetch and a
// Postgres query, recorded with --record and played again with tenma replay
use Http\{Client, Request, Response, Server};
use Threading\Task;

$port = (int) getenv('PORT');
$startedAt = time();

new Server($port, function (Request $request) use ($port) {
    switch ($request->path) {
        case '/ping':
            return "pong " . microtime(true) . "\n";
        case '/boom':
            throw new RuntimeException('boom');
        case '/data':
            $db = Data\Postgres::connect((string) getenv('DATABASE_URL'));
            return Response::json($db->first('SELECT now()::text AS now, $1::int + 1 AS n', [41]));
    }
    Task::delay(0.05);
    $fetched = new Client()->get("http://127.0.0.1:$port/ping");
    return Response::json([
        'time' => time(),
        'random' => random_int(1, 1_000_000),
        'bytes' => bin2hex(random_bytes(4)),
        'date' => date('Y-m-d H:i:s'),
        'uniqid' => uniqid(),
        'pid' => getmypid(),
        'fetched' => $fetched->body,
        'marker' => getenv('JOURNAL_MARKER') ?: 'none',
        // A replay runs with the recording's -d settings
        'memory' => ini_get('memory_limit'),
    ]);
})->start();
