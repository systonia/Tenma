<?php

namespace Http;

/**
 * An HTTP server in fiber mode: every request runs in its own Fiber, and waiting (a query, a fetch,
 * a sleep) suspends only that request. The handler gets a Request and returns a Response, a
 * StreamedResponse, a WebSocketResponse or a string.
 */
final class Server
{
    /** @var array<string, string> */
    private array $mounts = [];

    /**
     * With $static, tenma serves files itself (GET/HEAD, without PHP): ['/assets' => __DIR__ . '/public/assets',
     * '/favicon.svg' => __DIR__ . '/public/favicon.svg']. Under a prefix it looks in the directory; for
     * a file only its exact path matches; the longest prefix wins. Anything not found and all paths
     * outside go to the handler, the latter without touching the file system. A string means ['/' => $path]:
     * then every request is looked up, which costs PHP routes throughput. Files and directories
     * starting with a dot stay hidden.
     *
     * @param callable(Request): (Response|StreamedResponse|WebSocketResponse|string) $handler
     * @param string|array<string, string>|null $static
     */
    public function __construct(
        public readonly int $port,
        private readonly mixed $handler,
        string|array|null $static = null,
    ) {
        if (!is_callable($handler)) {
            throw new \TypeError('The handler of an Http\\Server must be callable, not ' . get_debug_type($handler));
        }
        foreach (is_string($static) ? ['/' => $static] : $static ?? [] as $prefix => $path) {
            if (!is_string($prefix) || !str_starts_with($prefix, '/') || str_contains($prefix, '?') || !is_string($path)) {
                throw new \ValueError("static needs URL prefixes starting with / and paths, not $prefix");
            }
            $this->mounts[$prefix === '/' ? '/' : rtrim($prefix, '/')] = $path;
        }
    }

    /** Registers the server: it listens once the script has finished and keeps the process alive. */
    public function start(): void
    {
        $handler = $this->handler;
        \tenma_listen($this->port, static function (array $raw, int $rid) use ($handler): void {
            // Every request as its own task: it responds as soon as the handler is done, and can be
            // cancelled together with all tasks it starts
            \Tenma\Internal\Current::$request = $rid;
            \Threading\Task::start(static function () use ($handler, $raw, $rid): void {
                try {
                    respond($rid, new Request($raw), $handler);
                } finally {
                    // The response is out: what the request started ends with it, only detach() outlives it
                    \Tenma\Internal\connectionEnded($rid);
                }
            }, false, null, $raw['method'] . ' ' . $raw['uri']);
        }, $this->mounts);
    }
}
