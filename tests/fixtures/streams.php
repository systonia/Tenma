<?php
// For tests/http.rs: streaming, server-sent events and WebSockets
use Tenma\Http\{EventStream, Request, Response, Stream, WebSocket};


$closed = false;
Tenma::serve((int) getenv('PORT'), function (Request $request) use (&$closed) {
    switch ($request->path) {
        case '/stream':
            return Response::stream(function (Stream $out) {
                foreach (['one', 'two', 'three'] as $word) {
                    $out->write("$word\n");
                    Tenma::sleep(0.2);
                }
            }, headers: ['content-type' => 'text/plain']);
        case '/events':
            return Response::events(function (EventStream $events) {
                $events->send('hello', event: 'greeting', id: '1');
                $events->send("two\nlines");
            });
        case '/forever':
            return Response::stream(function (Stream $out) use (&$closed) {
                try {
                    while (true) {
                        $out->write(str_repeat('x', 1024));
                        Tenma::sleep(0.01);
                    }
                } finally {
                    $closed = true;
                }
            });
        case '/exit-stream':
            return Response::stream(function (Stream $out) {
                $out->write("before exit\n");
                exit;
            });
        case '/closed':
            return $closed ? 'closed' : 'open';
        case '/ws-ignore':
            // Never reads: a client that floods anyway is disconnected instead of filling memory
            return Response::websocket(fn (WebSocket $socket) => Tenma::sleep(3));
        case '/ws':
            return Response::websocket(function (WebSocket $socket) {
                while (($message = $socket->receive()) !== null) {
                    $socket->send("echo: $message");
                }
            });
        default:
            return 'ok';
    }
});
