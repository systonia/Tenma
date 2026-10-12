<?php
// For tests/http.rs: messages between workers. Every worker subscribes at startup and keeps what it hears
$heard = [];
Tenma::subscribe('news', function (string $message) use (&$heard) {
    $heard[] = $message;
});
$muted = Tenma::subscribe('news', function () use (&$heard) {
    $heard[] = 'cancelled';
});
$muted->cancel();

Tenma::serve((int) getenv('PORT'), function (Tenma\Http\Request $request) use (&$heard) {
    if ($request->path === '/publish') {
        Tenma::publish('news', $request->query['message']);
        return "published\n";
    }
    // Made in a request: ends with it, so later messages find no callback of it
    if ($request->path === '/wait') {
        $message = null;
        Tenma::subscribe('news', function (string $received) use (&$message) {
            $message = $received;
        });
        while ($message === null) {
            Tenma::sleep(0.01);
        }
        return "waited for $message\n";
    }
    return implode(',', $heard) . "\n";
});
