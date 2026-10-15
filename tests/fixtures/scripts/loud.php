<?php
// For tests/http.rs: output a connected debugger client receives as output events
Tenma::serve((int) getenv('PORT'), function () {
    echo str_repeat('x', 1 << 20), "\n";
    return "ok\n";
});
