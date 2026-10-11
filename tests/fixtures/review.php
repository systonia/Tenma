<?php
// For tests/http.rs: fixes from a code review, fiber mode on PORT, classic mode on PORT + 1
use Tenma\Http\{EventStream, Request, Response, WebSocket};

$port = (int) getenv('PORT');
Tenma::serve($port, function (Request $request) {
    switch ($request->path) {
        case '/detach-exit':
            // exit() after detach() still ends only this request
            Tenma::detach(fn () => null);
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
                $waiting = Tenma::async(fn () => $socket->receive());
                Tenma::sleep(0.01);
                $waiting->cancel();
                $socket->send('ready');
                $socket->send('got ' . $socket->receive());
            });
        default:
            return json_encode(['path' => $request->path, 'query' => $request->query, 'cookie' => $request->headers['cookie'] ?? null]);
    }
});

Tenma::serveClassic($port + 1, function () {
    if (isset($_GET['exit'])) {
        exit;
    }
    echo json_encode([
        'post' => $_POST,
        'filter' => filter_input(INPUT_POST, 'secret'),
        'gc' => gc_status()['protected'],
    ]);
});
