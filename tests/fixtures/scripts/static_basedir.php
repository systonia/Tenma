<?php
// For tests/http.rs: open_basedir also applies to the static files directory
try {
    (new \Http\Server(18_116, fn () => 'x', static: '/etc'))->start();
    echo "allowed\n";
} catch (RuntimeException $error) {
    echo str_contains($error->getMessage(), 'open_basedir') ? "forbidden\n" : $error->getMessage();
}
