<?php
// For tests/http.rs: fixes from a code review, fiber mode on PORT, classic mode on PORT + 1
use Http\{EventStream, Request, Response, WebSocket};

$port = (int) getenv('PORT');
(new \Http\Server($port, function (Request $request) {
    switch ($request->path) {
        case '/detach-exit':
            // exit() after detach() still ends only this request
            \Threading\Task::detach(fn () => null);
            exit;
        case '/events':
            return Response::events(function (EventStream $events) {
                $events->send("a\rb\r\nc");
                $events->comment("x\ry");
                try {
                    $events->send('d', event: "e\rid: 9");
                } catch (InvalidArgumentException) {
                    $events->send('rejected');
                }
            });
        case '/ws':
            // A cancelled receive() gives the socket back to the next one
            return Response::websocket(function (WebSocket $socket) {
                $waiting = \Threading\Task::run(fn () => $socket->receive());
                \Threading\Task::delay(0.01);
                $waiting->cancel();
                $socket->send('ready');
                $socket->send('got ' . $socket->receive());
            });
        case '/all-cancelled':
            // all() is cancelled while its children clean up after one failed: the cancellation wins
            $outer = \Threading\Task::run(function () {
                try {
                    \Threading\Task::all(fn () => throw new RuntimeException('child'), function () {
                        try {
                            \Threading\Task::delay(1);
                        } finally {
                            \Threading\Task::delay(0.05);
                        }
                    });
                } catch (Throwable $error) {
                    return get_class($error);
                }
            });
            \Threading\Task::delay(0.02);
            $outer->cancel();
            return $outer->await();
        case '/forever':
            return Response::stream(function ($out) {
                while (true) {
                    $out->write('.');
                    \Threading\Task::delay(0.05);
                }
            });
        default:
            return json_encode(['path' => $request->path, 'query' => $request->query, 'cookie' => $request->headers['cookie'] ?? null]);
    }
}))->start();

(new \Http\ClassicServer($port + 1, function () {
    if (isset($_GET['exit'])) {
        exit;
    }
    echo json_encode([
        'post' => $_POST,
        'filter' => filter_input(INPUT_POST, 'secret'),
        'gc' => gc_status()['protected'],
    ]);
}))->start();
