<?php

namespace Http;

/**
 * An HTTP server for classic PHP code, for example a framework with its own front controller.
 * Before every call of $app, $_SERVER, $_GET, $_POST, $_FILES, $_COOKIE, $_REQUEST and php://input
 * are ready as under FPM; header(), http_response_code() and everything $app outputs become the response.
 * exit() and die() only end the request. $app also gets the raw body as an argument. Requests run one
 * after another, and the asynchronous functions throw.
 */
final class ClassicServer
{
    /** @param callable(string $rawBody): mixed $app */
    public function __construct(
        public readonly int $port,
        private readonly mixed $app,
    ) {
        if (!is_callable($app)) {
            throw new \TypeError('The app of an Http\\ClassicServer must be callable, not ' . get_debug_type($app));
        }
    }

    /** Registers the server: it listens once the script has finished and keeps the process alive. */
    public function start(): void
    {
        $app = $this->app;
        $server = $_SERVER;
        // Superglobals, headers and output buffers exist only once: so classic code runs without a
        // Fiber, one request after another, and asynchronous functions throw here
        \tenma_listen($this->port, static function (array $raw, int $rid) use ($app, $server): void {
            $request = new Request($raw);
            startClassic($server, $request, $rid);
            try {
                $app($request->body);
            } catch (\Throwable $error) {
                error_log('Uncaught ' . $error);
                \Tenma\Internal\Current::$failed = true;
            }
            // Tenma takes over shutdown functions and the response once no PHP code is running: finishClassic()
        });
    }
}
