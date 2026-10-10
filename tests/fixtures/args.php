<?php
// For tests/http.rs: arguments like in the PHP CLI
echo json_encode([$argv, $argc, $_SERVER['argv'], $_SERVER['argc'], (fn () => $GLOBALS['argv'][1])()]), "\n";
