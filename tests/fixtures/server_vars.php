<?php
// For tests/http.rs: $_SERVER in classic mode and the addresses in fiber mode
$keys = ['REMOTE_ADDR', 'SERVER_ADDR', 'SERVER_PORT', 'SERVER_NAME', 'REQUEST_SCHEME', 'SCRIPT_NAME', 'PHP_SELF', 'DOCUMENT_ROOT'];
Tenma::serveClassic((int) getenv('PORT'), function () use ($keys) {
    echo json_encode(array_combine($keys, array_map(fn ($key) => $_SERVER[$key], $keys)) + ['remote_port' => (int) $_SERVER['REMOTE_PORT'] > 0]);
});
Tenma::serve((int) getenv('PORT') + 1, fn (Tenma\Http\Request $request) => "$request->remoteAddress $request->serverPort {$request->host()}");
