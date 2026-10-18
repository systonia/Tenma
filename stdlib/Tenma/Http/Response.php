<?php

namespace Tenma\Http;

/**
 * A complete response with status, headers and body. A handler may also return a string, which becomes a
 * 200 with Content-Type text/plain. The static constructors build the usual kinds, header() and cookie()
 * add to it.
 */
final class Response
{
    /** @param array<string, string|list<string>> $headers several values as a list, for example for Set-Cookie */
    public function __construct(
        public string $body = '',
        public int $status = 200,
        public array $headers = ['content-type' => 'text/plain; charset=utf-8'],
    ) {}

    public static function json(mixed $data, int $status = 200): self
    {
        return new self(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n", $status, ['content-type' => 'application/json']);
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($html, $status, ['content-type' => 'text/html; charset=utf-8']);
    }

    /** Redirect; 302 (default), 303 after a POST, 301/308 for permanent ones. */
    public static function redirect(string $location, int $status = 302): self
    {
        return (new self('', $status, []))->header('location', $location);
    }

    /** Sets a header; with $append another value is added instead of replacing the existing one. */
    public function header(string $name, string $value, bool $append = false): self
    {
        checkHeader($name, $value);
        $key = strtolower($name);
        $this->headers[$key] = $append && isset($this->headers[$key]) ? [...(array) $this->headers[$key], $value] : $value;
        return $this;
    }

    /**
     * Sets a cookie like setcookie(): the value is encoded; without $expires (or with 0) it lasts until
     * the browser session ends. Defaults as is common today: HttpOnly and SameSite=Lax.
     */
    public function cookie(
        string $name,
        string $value,
        ?int $expires = null,
        string $path = '/',
        ?string $domain = null,
        bool $secure = false,
        bool $httpOnly = true,
        string $sameSite = 'Lax',
    ): self {
        // The same forbidden characters as setcookie(), plus the null byte
        $forbidden = "/[,; \t\r\n\013\014\0]/";
        if ($name === '' || str_contains($name, '=') || preg_match($forbidden, $name)) {
            throw new \ValueError("Invalid cookie name: $name");
        }
        if (preg_match($forbidden, $path . ($domain ?? ''))) {
            throw new \ValueError("Invalid path or domain in cookie $name");
        }
        if (!in_array($sameSite, ['', 'Lax', 'Strict', 'None'], true)) {
            throw new \ValueError("SameSite must be Lax, Strict, None or empty, not $sameSite");
        }
        // Browsers drop such a cookie without a word: say it here instead
        if ($sameSite === 'None' && !$secure) {
            throw new \ValueError("Cookie $name: SameSite=None needs secure: true, browsers drop it otherwise");
        }
        $cookie = "$name=" . rawurlencode($value);
        if ($expires) {
            $cookie .= '; Expires=' . gmdate('D, d M Y H:i:s', $expires) . ' GMT; Max-Age=' . max(0, $expires - time());
        }
        $cookie .= "; Path=$path";
        $cookie .= $domain !== null ? "; Domain=$domain" : '';
        $cookie .= $secure ? '; Secure' : '';
        $cookie .= $httpOnly ? '; HttpOnly' : '';
        $cookie .= $sameSite !== '' ? "; SameSite=$sameSite" : '';
        return $this->header('set-cookie', $cookie, append: true);
    }

    /**
     * A response whose body is produced bit by bit: the head goes out right away, every write() right
     * after it. The Fiber waits when the client cannot keep up.
     *
     * @param callable(Stream): void $writer
     * @param array<string, string|list<string>> $headers several values as a list, for example for Set-Cookie
     */
    public static function stream(callable $writer, int $status = 200, array $headers = ['content-type' => 'application/octet-stream']): StreamedResponse
    {
        return new StreamedResponse($writer(...), $status, $headers);
    }

    /**
     * Server-Sent Events: the browser reads along with `new EventSource(url)`.
     *
     * @param callable(EventStream): void $writer
     */
    public static function events(callable $writer): StreamedResponse
    {
        return new StreamedResponse(
            static fn (Stream $stream) => $writer(new EventStream($stream)),
            200,
            ['content-type' => 'text/event-stream', 'cache-control' => 'no-cache'],
        );
    }

    /**
     * Turns the connection into a WebSocket if the client asks for it; otherwise 426.
     *
     * @param callable(WebSocket): void $handler
     */
    public static function websocket(callable $handler): WebSocketResponse
    {
        return new WebSocketResponse($handler(...));
    }
}
