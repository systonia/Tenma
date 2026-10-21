<?php
// For tests/http.rs: port from the environment, one route throws
(new \Http\Server((int) getenv('PORT'), function (Http\Request $request) {
    static $count = 0;
    $count++;
    if ($request->path === '/null') {
        return null;   // forgotten return: 500, but no restart
    }
    if ($request->path === '/count') {
        return "count $count\n";
    }
    if ($request->path === '/boom') {
        throw new RuntimeException('broken');
    }
    return "Hello {$request->method} {$request->path}\n";
}))->start();
