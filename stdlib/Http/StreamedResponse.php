<?php

namespace Http;

/** A response whose body a writer produces while it is sent, from Response::stream() and Response::events(). */
final class StreamedResponse
{
    /** @param array<string, string|list<string>> $headers several values as a list, for example for Set-Cookie */
    public function __construct(
        public readonly \Closure $writer,
        public readonly int $status,
        public readonly array $headers,
    ) {}
}
