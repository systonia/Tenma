<?php

// `tenma lsp`: a language server for what the editor's own PHP server cannot know, such as the class
// `$this` is bound to in a template. It runs next to that server (Intelephense, PHP Tools); the editor
// merges the answers. Packages add their part through a Lsp\Provider.
//
// The server speaks LSP on stdin and stdout and keeps the open documents. Each project (the nearest
// directory with a composer.json above a file, so a workspace may hold several) gets a worker
// (`tenma lsp --worker DIR`) that loads its autoloader and its providers and answers by reflection.
// PHP cannot load a changed class again, so a saved .php file ends the workers, and the next
// question starts new ones.

namespace Tenma\Internal\Lsp;

use Lsp\Provider;

/** How long the worker may take for one answer before it is ended. */
const ANSWER_SECONDS = 5;

/** The semantic token types tenma sends, in this order; Lsp\Tokens names them. */
const TOKEN_TYPES = ['keyword', 'comment', 'variable', 'property', 'method', 'function', 'class', 'string', 'number', 'operator'];

function main(string $binary, array $args): void
{
    // Output of the project's code must not end up in the protocol, PHP's warnings and errors go to the
    // editor's log; the embed SAPI would print them as output, which the buffer below swallows
    ini_set('display_errors', 'stderr');
    ob_start(static fn (): string => '');
    register_shutdown_function(static function (): void {
        $error = error_get_last();
        if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            log("ended by {$error['message']} in {$error['file']}:{$error['line']}");
        }
    });
    if (($args[0] ?? null) === '--worker') {
        work((string) ($args[1] ?? getcwd()));
        return;
    }
    $in = fopen('php://stdin', 'rb');
    $server = new Server($binary, fopen('php://stdout', 'wb'));
    $ticked = 0.0;
    try {
        while (true) {
            // Once a second, also while the editor keeps sending: what the running app published (Problems::report)
            if (microtime(true) - $ticked >= 1) {
                $server->tick();
                $ticked = microtime(true);
            }
            $ready = [$in];
            $none = null;
            if (@stream_select($ready, $none, $none, 1) === 0) {
                continue;
            }
            $message = read($in);
            if ($message === null) {
                break;
            }
            if (!$server->handle($message)) {
                return;
            }
        }
        log('the editor closed the input');
    } catch (\Throwable $e) {
        log('ended by ' . $e::class . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    } finally {
        $server->stopWorkers();
    }
}

/** One LSP message (Content-Length framing), or null at the end of the input. */
function read($in): ?array
{
    $length = null;
    while (true) {
        $line = fgets($in);
        if ($line === false) {
            // The editor may hand over a socket, not a pipe: a read gives up after default_socket_timeout
            // without the input having ended, when the editor sends nothing for a while
            if (timedOut($in)) {
                continue;
            }
            return null;
        }
        $line = rtrim($line, "\r\n");
        if ($line === '') {
            if ($length !== null) {
                break;
            }
            continue;
        }
        if (stripos($line, 'Content-Length:') === 0) {
            $length = (int) trim(substr($line, 15));
        }
    }
    $body = '';
    while (strlen($body) < $length) {
        $chunk = fread($in, $length - strlen($body));
        if ($chunk === false || $chunk === '') {
            if (timedOut($in)) {
                continue;
            }
            return null;
        }
        $body .= $chunk;
    }
    $message = json_decode($body, true);
    return is_array($message) ? $message : [];
}

/** Whether the last read gave up after the timeout, the input still open. */
function timedOut($stream): bool
{
    return !feof($stream) && (stream_get_meta_data($stream)['timed_out'] ?? false);
}

function encode(mixed $value, int $flags = 0): string
{
    return json_encode($value, $flags | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
}

function log(string $message): void
{
    file_put_contents('php://stderr', "tenma lsp: $message\n");
}

function path(string $uri): ?string
{
    return str_starts_with($uri, 'file://') ? rawurldecode(substr($uri, 7)) : null;
}

function uri(string $path): string
{
    return 'file://' . implode('/', array_map(rawurlencode(...), explode('/', $path)));
}

/** Byte offset of an LSP position, whose character counts UTF-16 code units. */
function offset(string $text, int $line, int $character): int
{
    $start = 0;
    for ($i = 0; $i < $line; $i++) {
        $next = strpos($text, "\n", $start);
        if ($next === false) {
            return strlen($text);
        }
        $start = $next + 1;
    }
    $utf16 = mb_convert_encoding(lineAt($text, $start), 'UTF-16LE', 'UTF-8');
    return $start + strlen(mb_convert_encoding(substr($utf16, 0, 2 * $character), 'UTF-8', 'UTF-16LE'));
}

/** The text of the line that starts at byte $start. */
function lineAt(string $text, int $start): string
{
    $end = strpos($text, "\n", $start);
    return rtrim(substr($text, $start, $end === false ? null : $end - $start), "\r");
}

/** The length of a text in UTF-16 code units, as LSP counts characters. */
function utf16(string $text): int
{
    return intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
}

/** The line with number $line (from 0), and its length in UTF-16 code units. */
function lineLength(string $text, int $line): int
{
    $lines = explode("\n", $text);
    return intdiv(strlen(mb_convert_encoding(rtrim($lines[$line] ?? '', "\r"), 'UTF-16LE', 'UTF-8')), 2);
}

/** `tenma lsp --worker DIR`: loads the project and answers the server's questions. */
function work(string $root): void
{
    chdir($root);
    if (is_file("$root/vendor/autoload.php")) {
        require "$root/vendor/autoload.php";
    }
    $answers = new Answers(providers($root));
    $in = fopen('php://stdin', 'rb');
    $out = fopen('php://stdout', 'wb');
    while (($line = fgets($in)) !== false || timedOut($in)) {
        if ($line === false) {
            continue;
        }
        $question = json_decode($line, true);
        $result = null;
        try {
            $result = $answers->answer((string) ($question['m'] ?? ''), (array) ($question['a'] ?? []));
        } catch (\Throwable $e) {
            log(($question['m'] ?? '?') . ': ' . $e->getMessage());
        }
        // A provider's NAN or INF becomes 0 instead of ending the worker and every other provider's answer with it
        fwrite($out, encode(['r' => $result], JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n");
        fflush($out);
    }
}

/**
 * The providers named in `extra.tenma.lsp` of the project's composer.json and of every installed
 * package. A package's own composer.json counts, not the copy in installed.json: a package from a
 * path repository is a symlink whose composer.json may be newer than the last `composer update`.
 *
 * @return list<Provider>
 */
function providers(string $root): array
{
    $extras = [];
    $project = json_decode((string) @file_get_contents("$root/composer.json"), true);
    $extras[] = $project['extra']['tenma']['lsp'] ?? null;
    $installed = json_decode((string) @file_get_contents("$root/vendor/composer/installed.json"), true);
    foreach ($installed['packages'] ?? (array_is_list((array) $installed) ? (array) $installed : []) as $package) {
        $own = is_string($package['install-path'] ?? null) ? json_decode((string) @file_get_contents("$root/vendor/composer/{$package['install-path']}/composer.json"), true) : null;
        $extras[] = is_array($own) ? $own['extra']['tenma']['lsp'] ?? null : $package['extra']['tenma']['lsp'] ?? null;
    }
    $providers = [];
    foreach ($extras as $extra) {
        foreach ((array) $extra as $class) {
            try {
                $provider = is_string($class) && class_exists($class) ? new $class($root) : null;
                if ($provider instanceof Provider) {
                    $providers[] = $provider;
                } else {
                    log(var_export($class, true) . ' is no Lsp\Provider');
                }
            } catch (\Throwable $e) {
                log("$class: " . $e->getMessage());
            }
        }
    }
    return $providers;
}
