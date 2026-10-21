<?php

namespace Http;

/**
 * Outgoing HTTP requests, without blocking the server: only the Fiber waits. Network errors and a
 * response that is not complete within $timeout seconds throw; HTTP error codes are not errors, they
 * are in the response's status.
 */
final class Client
{
    public function __construct(public readonly float $timeout = 30.0) {}

    /** @param array<string, string> $headers */
    public function get(string $url, array $headers = []): ClientResponse
    {
        return $this->send('GET', $url, $headers);
    }

    /** @param array<string, string> $headers */
    public function post(string $url, string $body = '', array $headers = []): ClientResponse
    {
        return $this->send('POST', $url, $headers, $body);
    }

    /** @param array<string, string> $headers */
    public function send(string $method, string $url, array $headers = [], string $body = ''): ClientResponse
    {
        \Tenma\Internal\requireFiber();
        $lines = headerLines($headers);
        [$status, $responseHeaders, $responseBody] = \Tenma\Internal\await(\tenma_op_fetch($method, $url, $lines, $body, (int) ceil($this->timeout * 1000)));
        return new ClientResponse($status, $responseHeaders, $responseBody);
    }
}
