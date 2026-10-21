<?php

namespace Http;

/** Server-Sent Events on a stream. */
final class EventStream
{
    public function __construct(private readonly Stream $stream) {}

    public function send(string $data, ?string $event = null, ?string $id = null): void
    {
        // EventSource breaks lines at \r too: a line break in id or event would forge fields
        if (strpbrk(($id ?? '') . ($event ?? ''), "\r\n") !== false) {
            throw new \InvalidArgumentException('id and event must not contain line breaks');
        }
        $message = ($id !== null ? "id: $id\n" : '') . ($event !== null ? "event: $event\n" : '');
        foreach (preg_split('/\r\n|\r|\n/', $data) as $line) {
            $message .= "data: $line\n";
        }
        $this->stream->write("$message\n");
    }

    /** A comment keeps the connection alive through proxies; the browser ignores it. */
    public function comment(string $text = ''): void
    {
        $this->stream->write(': ' . implode("\n: ", preg_split('/\r\n|\r|\n/', $text)) . "\n\n");
    }
}
