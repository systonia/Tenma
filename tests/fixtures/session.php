<?php
// For tests/http.rs: sessions in classic mode, one counter per visitor
(new \Http\ClassicServer((int) getenv('PORT'), function () {
    if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/peek') {
        // Without session_start() nothing from the previous visitor may be here
        echo isset($_SESSION) ? 'leak' : 'empty';
        return;
    }
    session_start();
    $_SESSION['count'] = ($_SESSION['count'] ?? 0) + 1;
    echo "visit {$_SESSION['count']}";
}))->start();
