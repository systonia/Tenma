<?php

namespace Tenma\Http;

/**
 * An HTTP request in fiber mode, the argument of the handler of Tenma::serve(). Query, headers and body
 * are read in full before the handler runs; form(), files() and cookies() parse them on demand.
 */
final class Request
{
    public readonly string $method;
    public readonly string $uri;
    public readonly string $path;
    /** @var array<string, string|array<mixed>> */
    public readonly array $query;
    /** @var array<string, string> names in lowercase */
    public readonly array $headers;
    public readonly string $body;
    /** IP address and port of the client, or of the proxy in front of it. */
    public readonly string $remoteAddress;
    public readonly int $remotePort;
    /** Address and port the connection arrived on. */
    public readonly string $serverAddress;
    public readonly int $serverPort;
    /** Whether the connection came over TLS, with --tls-cert. Behind a proxy that ends TLS it is false. */
    public readonly bool $https;
    /** The version the client spoke: HTTP/1.1, HTTP/2 (over TLS) or HTTP/1.0. */
    public readonly string $protocol;

    /** @param array{method: string, uri: string, headers: array<string, string>, body: string, remote_addr: string, remote_port: int, server_addr: string, server_port: int, https?: bool, protocol?: string} $raw */
    public function __construct(array $raw)
    {
        $this->method = $raw['method'];
        $this->uri = $raw['uri'];
        // parse_url() would take "//host/admin" for a host and the path /admin
        [$path, $queryString] = str_starts_with($raw['uri'], '/')
            ? explode('?', $raw['uri'], 2) + [1 => '']
            : [parse_url($raw['uri'], PHP_URL_PATH) ?: '/', parse_url($raw['uri'], PHP_URL_QUERY) ?? ''];
        $this->path = $path;
        parse_str($queryString, $query);
        $this->query = $query;
        $this->headers = $raw['headers'];
        $this->body = $raw['body'];
        $this->remoteAddress = $raw['remote_addr'];
        $this->remotePort = $raw['remote_port'];
        $this->serverAddress = $raw['server_addr'];
        $this->serverPort = $raw['server_port'];
        $this->https = $raw['https'] ?? false;
        $this->protocol = $raw['protocol'] ?? 'HTTP/1.1';
    }

    /**
     * The host from the Host header, without port; otherwise the address the connection arrived on. The
     * client controls the Host header: for links in emails or for redirects, only use it checked against
     * a list of allowed hosts.
     */
    public function host(): string
    {
        $host = $this->header('host') ?? '';
        return $host === '' ? $this->serverAddress : (string) preg_replace('/:\d+$/', '', $host);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** The body as JSON; throws on invalid JSON. */
    public function json(): mixed
    {
        return json_decode($this->body, true, flags: JSON_THROW_ON_ERROR);
    }

    /** @var array<string, mixed>|null */
    private ?array $cookies = null;
    /** @var array{array<string, mixed>, array<string, mixed>}|null */
    private ?array $form = null;

    /**
     * The cookies like $_COOKIE under FPM, via PHP's own parser: the first of several with the same name
     * wins, a[b] becomes an array, dots and spaces in names become underscores.
     *
     * @return array<string, mixed>
     */
    public function cookies(): array
    {
        return $this->cookies ??= \tenma_parse_cookies($this->header('cookie') ?? '', false);
    }

    public function cookie(string $name): ?string
    {
        $value = $this->cookies()[$name] ?? null;
        return is_string($value) ? $value : null;
    }

    /**
     * Form fields from application/x-www-form-urlencoded or multipart/form-data, with the same
     * semantics as $_POST (a[] appends, a[b] nests, duplicates overwrite).
     *
     * @return array<string, mixed>
     */
    public function form(): array
    {
        return $this->parseForm()[0];
    }

    /**
     * Uploaded files from multipart/form-data, by field name; names as with form() (name[] becomes
     * a list, name[a] nests).
     *
     * @return array<string, mixed> UploadedFile or arrays of them
     */
    public function files(): array
    {
        return $this->parseForm()[1];
    }

    /** @return array{array<string, mixed>, array<string, mixed>} */
    private function parseForm(): array
    {
        if ($this->form !== null) {
            return $this->form;
        }
        $type = $this->header('content-type') ?? '';
        if (stripos($type, 'application/x-www-form-urlencoded') === 0) {
            parse_str($this->body, $fields);
            return $this->form = [$fields, []];
        }
        if (stripos($type, 'multipart/form-data') === 0 && preg_match('/boundary=(?:"([^"]+)"|([^;\s]+))/i', $type, $match)) {
            return $this->form = Multipart::parse($this->body, $match[1] !== '' ? $match[1] : $match[2]);
        }
        return $this->form = [[], []];
    }
}
