<?php
// For tests/http.rs: messages between workers. Every worker subscribes at startup and keeps what it hears
$heard = [];
(new \Messaging\Channel('news'))->subscribe(function (string $message) use (&$heard) {
    $heard[] = $message;
});
$muted = (new \Messaging\Channel('news'))->subscribe(function () use (&$heard) {
    $heard[] = 'cancelled';
});
$muted->cancel();

(new \Http\Server((int) getenv('PORT'), function (Http\Request $request) use (&$heard) {
    if ($request->path === '/publish') {
        (new \Messaging\Channel('news'))->publish($request->query['message']);
        return "published\n";
    }
    if ($request->path === '/kill-publisher') {
        // The connection the Redis bridge publishes on: its next PUBLISH fails
        $redis = Data\Redis::connect('redis://127.0.0.1:6379');
        foreach (explode("\n", trim($redis->command('CLIENT', 'LIST', 'TYPE', 'normal'))) as $line) {
            parse_str(str_replace(' ', '&', $line), $client);
            if ($client['cmd'] === 'publish' && (int) $client['age'] < 30) {
                $redis->command('CLIENT', 'KILL', 'ID', $client['id']);
            }
        }
        return "killed\n";
    }
    // Made in a request: ends with it, so later messages find no callback of it
    if ($request->path === '/wait') {
        $message = null;
        (new \Messaging\Channel('news'))->subscribe(function (string $received) use (&$message) {
            $message = $received;
        });
        while ($message === null) {
            \Threading\Task::delay(0.01);
        }
        return "waited for $message\n";
    }
    return implode(',', $heard) . "\n";
}))->start();
