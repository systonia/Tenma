<?php

namespace Tenma\Http;

/** Accepts the request as a WebSocket and runs the handler with the connection, from Response::websocket(). */
final class WebSocketResponse
{
    public function __construct(public readonly \Closure $handler) {}
}
