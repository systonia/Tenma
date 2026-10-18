<?php

namespace Tenma\Http;

/** Response from fetch(). */
final class FetchResponse
{
    /** @param list<string> $headers header lines "Name: Value" */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {}

    public function header(string $name): ?string
    {
        foreach ($this->headers as $line) {
            [$key, $value] = explode(':', $line, 2) + [1 => ''];
            if (strcasecmp($key, $name) === 0) {
                return trim($value);
            }
        }
        return null;
    }

    public function json(): mixed
    {
        return json_decode($this->body, true, flags: JSON_THROW_ON_ERROR);
    }
}
