<?php
// For tests/http.rs: what a classic request changes, the next one does not see
ini_set('precision', '10');   // set at startup: stays
$kept = null;
(new \Http\ClassicServer((int) getenv('PORT'), function () use (&$kept) {
    if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/set') {
        ini_set('precision', '3');
        set_time_limit(5);
        $kept = fopen('php://input', 'r');
        echo 'set';
        return;
    }
    // The old php://input handle must read neither freed memory nor the body of this request
    try {
        $read = stream_get_contents($kept);
    } catch (TypeError) {
        $read = 'closed';
    }
    echo ini_get('precision'), ' ', ini_get('max_execution_time'), ' ', $read;
}))->start();
