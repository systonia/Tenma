<?php
// For tests/http.rs: classic code with superglobals, header() and echo
Tenma::serveClassic((int) getenv('PORT'), function (string $body) {
    static $count = 0;
    $count++;
    http_response_code(isset($_GET['missing']) ? 404 : 200);
    header('X-Count: ' . $count);
    header('Set-Cookie: a=1', false);
    header('Set-Cookie: b=2', false);
    echo "{$_SERVER['REQUEST_METHOD']} {$_GET['name']} {$_COOKIE['session']}";
});
