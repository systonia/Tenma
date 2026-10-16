<?php
// For tests/http.rs: register_shutdown_function() per request in classic mode, also in error cases
register_shutdown_function(function () {
    echo "REGISTERED-AT-STARTUP\n";
});
$count = 0;
Tenma::serveClassic((int) getenv('PORT'), function () use (&$count) {
    $count++;
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($path === '/count') {
        echo $count;
        return;
    }
    if ($path === '/peek') {
        echo isset($_SESSION) || session_status() === PHP_SESSION_ACTIVE ? 'leak' : 'empty';
        return;
    }
    register_shutdown_function(function () use ($path) {
        echo ' after';
        match ($path) {
            '/throw-shutdown' => throw new RuntimeException('in shutdown'),
            '/exit-shutdown' => exit,
            // Like a framework's error page
            '/fatal' => print(' error page: ' . (error_get_last()['type'] === E_ERROR ? 'fatal' : '?')),
            // A backtrace after a compile error must not reach into skipped frames
            '/compile-error' => print(' trace ' . count((new Exception())->getTrace())),
            // An error page larger than any socket buffer: must arrive in full despite the restart
            '/big-fatal' => print(str_repeat('x', 3_000_000)),
            default => null,
        };
    });
    echo 'request';
    match ($path) {
        '/exit', '/exit-shutdown' => exit,
        '/throw-shutdown' => session_start(),
        '/fatal' => str_repeat('x', 512 * 1024 * 1024),
        '/compile-error' => require __DIR__ . '/broken_include.php',
        '/big-fatal' => str_repeat('x', 512 * 1024 * 1024),
        default => null,
    };
});
