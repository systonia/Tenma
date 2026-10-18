<?php

namespace Tenma\Http;

/** Body of a streaming response. */
final class Stream
{
    public function __construct(private readonly int $rid) {}

    public function write(string $chunk): void
    {
        \Tenma\Internal\requireFiber();
        $result = \tenma_stream_write($this->rid, $chunk);
        try {
            $result > 0 && \Tenma\Internal\await($result);
        } catch (\RuntimeException) {
            $result = -1;
        }
        if ($result < 0) {
            throw new ConnectionClosed('The client closed the connection');
        }
    }
}
