<?php
// For tests/http.rs: a durable function (Threading\Durable) that waits for a signal and a timer, and
// finishes in whichever process runs once it was started. MARKER names a file it appends its steps to.
use Http\{Request, Server};
use Threading\{Durable, Signal, Task};

$marker = (string) getenv('MARKER');
$note = fn (string $text) => file_put_contents($marker, $text . "\n", FILE_APPEND);

Durable::define('order', function (string $key) use ($note) {
    $note("started $key by " . getenv('WHO'));
    $began = time();
    $payment = Signal::receive("payment:$key", timeout: 20);
    $note("paid " . json_encode($payment) . " by " . getenv('WHO'));
    // A durable timer: the fiber ends, the database wakes the function
    Task::delay(1.5);
    $note("timer by " . getenv('WHO') . ' time ' . ($began === time() ? 'repeated' : 'ran on'));
    // A value that the journal repeats: the same in every process
    $note('random ' . random_int(1, 1_000_000_000));
    $note("done $key");
}, version: (int) (getenv('VERSION') ?: 1));

new Server((int) getenv('PORT'), function (Request $request) {
    switch ($request->path) {
        case '/start':
            return (Durable::start('order', $request->query['key'] ?? '1') ? 'started' : 'not started') . "\n";
        case '/pay':
            Signal::send('payment:' . ($request->query['key'] ?? '1'), ['failed' => false, 'amount' => 42]);
            return "sent\n";
        case '/early':
            // A signal before anyone waits: kept for the next receive
            Signal::send('payment:' . ($request->query['key'] ?? 'early'), 'kept');
            return "sent\n";
    }
    return "ok\n";
})->start();
