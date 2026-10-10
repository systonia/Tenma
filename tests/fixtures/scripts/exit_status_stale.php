<?php
// An exit(5) in a request only ends the request; a later die("…") ends tenma with 0
Tenma::serve((int) getenv('PORT'), function (Tenma\Http\Request $request) {
    if ($request->path === '/exit5') {
        exit(5);
    }
    Tenma::setTimeout(fn () => die("bye\n"), 0.01);
    return "ok\n";
});
