<?php
// For tests/http.rs: static files only under prefixes, plus a single file
(new \Http\Server((int) getenv('PORT'), fn (Http\Request $request) => "php: {$request->path}\n", static: [
    '/assets/' => __DIR__ . '/public',
    '/assets/deep' => __DIR__ . '/public/sub',
    '/robots.txt' => __DIR__ . '/public/sub/digits.txt',
]))->start();
