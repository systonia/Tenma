<?php
// For tests/http.rs: streaming, server-sent events and WebSockets
use Tenma\Http\{EventStream, Request, Response, Stream, WebSocket};


$closed = false;
$quiet = false;
$ticks = 0;
$flooded = false;
$slept = '';
Tenma::serve((int) getenv('PORT'), function (Request $request) use (&$closed, &$quiet, &$ticks, &$flooded, &$slept) {
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
            return Response::stream(function (Stream $out) use (&$closed, $request) {
                try {
                    while (true) {
                        $out->write(str_repeat('x', 1024 * (int) ($request->query['kb'] ?? 1)));
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
        case '/throw-stream':
        case '/fatal-stream':
            // Cut off midway: the client must be able to tell, not get a complete-looking response
            return Response::stream(function (Stream $out) use ($request) {
                $out->write("first part\n");
                Tenma::sleep(0.01);
                $request->path === '/fatal-stream' ? str_repeat('x', 512 * 1024 * 1024) : throw new RuntimeException('export failed');
            });
        case '/ws-throw':
        case '/ws-fatal':
            return Response::websocket(function (WebSocket $socket) use ($request) {
                $socket->send('hi');
                Tenma::sleep(0.01);
                $request->path === '/ws-fatal' ? str_repeat('x', 512 * 1024 * 1024) : throw new RuntimeException('handler failed');
            });
        case '/quiet':
            // Waits for something that does not come (a rare event): the client leaving must end it anyway
            return Response::events(function (EventStream $events) use (&$quiet) {
                $events->comment('waiting');
                try {
                    Tenma::sleep(10);
                } finally {
                    $quiet = true;
                }
            });
        case '/quiet-closed':
            return $quiet ? 'closed' : 'open';
        case '/closed':
            return $closed ? 'closed' : 'open';
        case '/ws-ignore':
            // Never reads: a client that floods anyway is disconnected instead of filling memory
            return Response::websocket(fn (WebSocket $socket) => Tenma::sleep(3));
        case '/ws-ticker':
            // The interval belongs to the connection: it stops when the client leaves
            return Response::websocket(function (WebSocket $socket) use (&$ticks) {
                Tenma::setInterval(function () use ($socket, &$ticks) {
                    $ticks++;
                    $socket->send('tick');
                }, 0.02);
                while ($socket->receive() !== null) {
                }
            });
        case '/ws-burst':
            // More messages at once than the queue holds: send() waits for the client instead of failing
            return Response::websocket(function (WebSocket $socket) {
                for ($i = 1; $i <= 1000; $i++) {
                    $socket->send("message $i");
                }
            });
        case '/ws-flood':
            // To a client that does not read: send() waits, until tenma gives up on the client
            return Response::websocket(function (WebSocket $socket) use (&$flooded) {
                try {
                    while (true) {
                        $socket->send(str_repeat('x', 64 * 1024));
                    }
                } finally {
                    $flooded = true;
                }
            });
        case '/flooded':
            return $flooded ? 'closed' : 'open';
        case '/ws-sleep':
            // Waits for something else than a message: the client leaving must end it anyway
            return Response::websocket(function (WebSocket $socket) use (&$slept) {
                $slept = 'open';
                $socket->send('ready');
                try {
                    Tenma::sleep(10);
                    $slept = 'woke';
                } catch (Tenma\Cancelled) {
                    $slept = 'cancelled';
                }
            });
        case '/slept':
            return $slept;
        case '/ticks':
            return "ticks $ticks";
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
