<?php
// For tests/http.rs: what the client must not influence in classic mode
(new \Http\ClassicServer((int) getenv('PORT'), function () {
    echo json_encode([
        'server_name' => $_SERVER['SERVER_NAME'],
        'host' => $_SERVER['HTTP_HOST'],
        'cookies' => $_COOKIE,
        'forwarded' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
        'request' => $_REQUEST,
    ]);
}))->start();
