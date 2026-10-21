<?php
// An exit(5) in a request only ends the request; a later die("…") ends tenma with 0
(new \Http\Server((int) getenv('PORT'), function (Http\Request $request) {
    if ($request->path === '/exit5') {
        exit(5);
    }
    // Background work that outlives the response; die() there ends tenma
    \Threading\Task::detach(function () {
        \Threading\Task::delay(0.01);
        die("bye\n");
    });
    return "ok\n";
}))->start();
