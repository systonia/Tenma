<?php

namespace Http;

/** An accepted WebSocket connection, passed to the handler of Response::websocket(). */
final class WebSocket
{
    public function __construct(private readonly int $rid) {}

    /**
     * Waits for the next message without blocking the server; null when the client has closed. Text and binary
     * messages both arrive as a string of their bytes.
     */
    public function receive(): ?string
    {
        \Tenma\Internal\requireFiber();
        $op = \tenma_ws_receive($this->rid);
        return $op === 0 ? null : \Tenma\Internal\await($op);
    }

    /**
     * Sends $data as a text message if it is valid UTF-8, otherwise as a binary one. Waits while the client
     * is behind, like Stream::write().
     */
    public function send(string $data): void
    {
        $result = \tenma_ws_send($this->rid, $data);
        try {
            $result > 0 && \Tenma\Internal\await($result);
        } catch (\RuntimeException) {
            $result = -1;
        }
        if ($result < 0) {
            throw new ConnectionClosed('The WebSocket is closed');
        }
    }

    public function close(): void
    {
        \tenma_ws_close($this->rid, true);
    }
}
