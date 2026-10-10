<?php
// For tests/http.rs: a fatal error and a request that sleeps
use Tenma\Http\Request;


// Every interpreter start appears once on stdout; tests/http.rs counts restarts with it
echo "start\n";

Tenma::serve((int) getenv('PORT'), function (Request $request): string {
    static $count = 0;
    $count++;
    switch ($request->path) {
        case '/oom':
            // More than memory_limit: "Allowed memory size exhausted", a fatal error
            return str_repeat('x', 512 * 1024 * 1024);
        case '/slow':
            Tenma::sleep(0.5);
            return "slept enough\n";
        case '/upload':
            return strlen($request->body) . "\n";
        default:
            return "request $count\n";
    }
});
