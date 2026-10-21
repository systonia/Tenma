<?php
// Line numbers matter: tests/http.rs stops on line 5. $config comes from another file, so it lives
// in the symbol table itself, not in a variable slot of this script
require __DIR__ . '/inspect_config.php';
$port = (int) getenv('PORT');
(new \Http\Server($port, fn () => "ok\n"))->start();
