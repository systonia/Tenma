<?php
// `tenma lsp`: a language server for what the editor's own PHP server cannot know, such as the class
// `$this` is bound to in a template. It runs next to that server (Intelephense, PHP Tools); the editor
// merges the answers. Packages add their part through a Tenma\Lsp\Provider.
//
// The server speaks LSP on stdin and stdout and keeps the open documents. Each project (the nearest
// directory with a composer.json above a file, so a workspace may hold several) gets a worker
// (`tenma lsp --worker DIR`) that loads its autoloader and its providers and answers by reflection.
// PHP cannot load a changed class again, so a saved .php file ends the workers, and the next
// question starts new ones.

namespace Tenma\Internal\Lsp;

use Tenma\Lsp\Provider;

/** How long the worker may take for one answer before it is ended. */
const ANSWER_SECONDS = 5;

/** The semantic token types tenma sends, in this order; Tenma\Lsp\Tokens names them. */
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
            // Once a second, also while the editor keeps sending: what the running app published (Tenma::diagnostics)
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

final class Server
{
    /** @var array<string, string> uri => text of the open documents */
    private array $documents = [];

    /** @var array<string, Worker> project directory => its worker */
    private array $workers = [];

    /** @var array<string, ?string> directory => the project it belongs to */
    private array $projects = [];

    /** @var array<string, list<array>> uri => the providers' diagnostics of an open document */
    private array $checked = [];

    /** @var array<string, array<string, list<array>>> project => path => what the running app published there */
    private array $published = [];

    /**
     * @var array<string, string> path => the URI the client spells it with. Clients encode differently (Neovim keeps
     * @ and + as they are): a URI of our own would name a second document.
     */
    private array $uris = [];

    /** @var array<string, string> project => its lists as they were last read: names and hashes */
    private array $lists = [];

    /** @param resource $out */
    public function __construct(private readonly string $binary, private $out)
    {
    }

    /**
     * Reads again the lists Tenma::diagnostics() keeps in the projects of the open documents, if they changed,
     * and publishes the files whose problems changed.
     */
    public function tick(): void
    {
        foreach (array_unique(array_filter($this->projects)) as $project) {
            $directory = \Tenma\Internal\Diagnostics::directory($project);
            $files = glob("$directory/*.json") ?: [];
            // By content: a rewrite within the same second with the same size is a change too
            $state = implode('|', array_map(static fn (string $f) => $f . ':' . md5((string) @file_get_contents($f)), $files));
            if (($this->lists[$project] ?? '') === $state) {
                continue;
            }
            $this->lists[$project] = $state;
            $before = $this->published[$project] ?? [];
            $now = [];
            foreach ($files as $file) {
                // app.waypoint-live.json: published by the script, as 'waypoint-live'
                $source = (string) preg_replace('/^(app|dev)\.|\.json$/', '', basename($file));
                $list = json_decode((string) @file_get_contents($file), true);
                foreach (is_array($list) ? $list : [] as $problem) {
                    if (is_array($problem) && is_string($problem['file'] ?? null)) {
                        $now[$problem['file']][] = ['source' => $source] + $problem;
                    }
                }
            }
            $this->published[$project] = $now;
            foreach (array_keys($before + $now) as $path) {
                if (($before[$path] ?? null) !== ($now[$path] ?? null)) {
                    $this->publish($this->uris[$path] ?? uri((string) $path));
                }
            }
        }
    }

    /** The providers' diagnostics of a document and what the app published for its file, together. */
    private function publish(string $uri): void
    {
        $diagnostics = $this->checked[$uri] ?? [];
        $file = path($uri);
        foreach ($this->published as $files) {
            foreach ($files[$file ?? ''] ?? [] as $problem) {
                $text = $this->documents[$uri] ?? ($file !== null && is_file($file) ? (string) file_get_contents($file) : '');
                $line = max(0, (int) ($problem['line'] ?? 1) - 1);
                // An app that checks what a provider checks too: once is enough, the provider's follows the typing
                foreach ($this->checked[$uri] ?? [] as $own) {
                    if ($own['range']['start']['line'] === $line && $own['message'] === (string) ($problem['message'] ?? '')) {
                        continue 2;
                    }
                }
                // The column counts bytes, as everywhere in the Provider API; past the end it is the end
                $lineText = rtrim(explode("\n", $text)[$line] ?? '', "\r");
                $character = utf16(substr($lineText, 0, max(0, (int) ($problem['column'] ?? 1) - 1)));
                $diagnostics[] = [
                    'range' => ['start' => ['line' => $line, 'character' => $character], 'end' => ['line' => $line, 'character' => utf16($lineText)]],
                    'severity' => match ($problem['severity'] ?? 'error') { 'warning' => 2, 'info' => 3, default => 1 },
                    'source' => (string) $problem['source'],
                    'message' => (string) ($problem['message'] ?? ''),
                ];
            }
        }
        $this->send(['jsonrpc' => '2.0', 'method' => 'textDocument/publishDiagnostics', 'params' => ['uri' => $uri, 'diagnostics' => $diagnostics]]);
    }

    /** False once the client says exit. */
    public function handle(array $message): bool
    {
        $method = $message['method'] ?? null;
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];
        $id = $message['id'] ?? null;
        if (!is_string($method)) {
            return true;
        }
        try {
            $result = match ($method) {
                'initialize' => $this->initialize($params),
                'shutdown' => null,
                'exit' => false,
                'textDocument/didOpen' => $this->open($params['textDocument']['uri'] ?? '', $params['textDocument']['text'] ?? ''),
                'textDocument/didChange' => $this->open($params['textDocument']['uri'] ?? '', end($params['contentChanges'])['text'] ?? ''),
                'textDocument/didSave' => $this->saved($params['textDocument']['uri'] ?? '', $params['text'] ?? null),
                'textDocument/didClose' => $this->close($params['textDocument']['uri'] ?? ''),
                'textDocument/completion' => $this->completion($params),
                'textDocument/hover' => $this->hover($params),
                'textDocument/definition' => $this->definition($params),
                'textDocument/semanticTokens/full' => $this->tokens($params),
                default => new \LogicException("unknown method $method"),
            };
        } catch (\Throwable $e) {
            log("$method: " . $e->getMessage());
            $result = $e;
        }
        if ($result === false) {
            return false;
        }
        if ($id !== null) {
            $this->send(['jsonrpc' => '2.0', 'id' => $id] + ($result instanceof \Throwable
                ? ['error' => ['code' => $result instanceof \LogicException ? -32601 : -32603, 'message' => $result->getMessage()]]
                : ['result' => $result]));
        }
        return true;
    }

    public function stopWorkers(): void
    {
        foreach ($this->workers as $worker) {
            $worker->stop();
        }
        $this->workers = [];
    }

    private function send(array $message): void
    {
        $body = encode($message);
        fwrite($this->out, 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body);
        fflush($this->out);
    }

    private function initialize(array $params): array
    {
        return [
            'capabilities' => [
                // The whole text on every change: templates are small, and nothing can drift
                'textDocumentSync' => ['openClose' => true, 'change' => 1, 'save' => ['includeText' => false]],
                'completionProvider' => ['triggerCharacters' => ['>', '"', '<', ' ', '-']],
                'hoverProvider' => true,
                'definitionProvider' => true,
                'semanticTokensProvider' => ['legend' => ['tokenTypes' => TOKEN_TYPES, 'tokenModifiers' => []], 'full' => true],
            ],
            'serverInfo' => ['name' => 'tenma', 'version' => VERSION],
        ];
    }

    /** Asks the worker of the project $file belongs to; null outside a project. */
    private function ask(string $method, string $file, mixed ...$args): mixed
    {
        $root = $this->project(dirname($file));
        if ($root === null) {
            return null;
        }
        $worker = $this->workers[$root] ??= new Worker($this->binary, $root);
        $answer = $worker->ask($method, [$file, ...$args]);
        if (!$worker->alive()) {
            $worker->stop();
            unset($this->workers[$root]);
        }
        return $answer;
    }

    /** The nearest directory with a composer.json, $directory included. */
    private function project(string $directory): ?string
    {
        if (array_key_exists($directory, $this->projects)) {
            return $this->projects[$directory];
        }
        $parent = dirname($directory);
        return $this->projects[$directory] = is_file("$directory/composer.json") ? $directory : ($parent !== $directory ? $this->project($parent) : null);
    }

    private function open(string $uri, string $text): null
    {
        $this->documents[$uri] = $text;
        $path = path($uri);
        if ($path !== null) {
            $this->uris[$path] = $uri;
        }
        $this->diagnose($uri);
        return null;
    }

    private function saved(string $uri, ?string $text): null
    {
        if ($text !== null) {
            $this->documents[$uri] = $text;
        }
        // A class may have changed, in this project or in one that uses it: only new workers see it
        if (str_ends_with($uri, '.php')) {
            $this->stopWorkers();
            foreach (array_keys($this->documents) as $open) {
                $this->diagnose($open);
            }
        }
        return null;
    }

    private function close(string $uri): null
    {
        unset($this->documents[$uri], $this->checked[$uri]);
        // What the app published stays until the app takes it back
        $this->publish($uri);
        return null;
    }

    private function diagnose(string $uri): void
    {
        $file = path($uri);
        $text = $this->documents[$uri] ?? null;
        if ($file === null || $text === null) {
            return;
        }
        $diagnostics = [];
        foreach ((array) $this->ask('diagnostics', $file, $text) as $problem) {
            $line = max(0, (int) ($problem['line'] ?? 1) - 1);
            $diagnostics[] = [
                'range' => ['start' => ['line' => $line, 'character' => 0], 'end' => ['line' => $line, 'character' => lineLength($text, $line)]],
                'severity' => match ($problem['severity'] ?? 'error') { 'warning' => 2, 'info' => 3, default => 1 },
                'source' => 'tenma',
                'message' => (string) ($problem['message'] ?? ''),
            ];
        }
        $this->checked[$uri] = $diagnostics;
        $this->publish($uri);
    }

    /** @return array{string, string, int}|null file, text, byte offset of the position */
    private function position(array $params): ?array
    {
        $uri = $params['textDocument']['uri'] ?? '';
        $file = path($uri);
        $text = $this->documents[$uri] ?? ($file !== null && is_file($file) ? file_get_contents($file) : null);
        if ($file === null || !is_string($text)) {
            return null;
        }
        return [$file, $text, offset($text, (int) ($params['position']['line'] ?? 0), (int) ($params['position']['character'] ?? 0))];
    }

    private function completion(array $params): array
    {
        $at = $this->position($params);
        $items = [];
        foreach ($at === null ? [] : (array) $this->ask('completions', ...$at) as $item) {
            $items[] = array_filter([
                'label' => (string) ($item['label'] ?? ''),
                'kind' => match ($item['kind'] ?? null) { 'method' => 2, 'property' => 10, 'class' => 7, 'keyword' => 14, default => 12 },
                'detail' => $item['detail'] ?? null,
                'insertText' => $item['insert'] ?? null,
            ], static fn (mixed $value): bool => $value !== null);
        }
        return ['isIncomplete' => false, 'items' => $items];
    }

    private function hover(array $params): ?array
    {
        $at = $this->position($params);
        $markdown = $at === null ? null : $this->ask('hover', ...$at);
        return is_string($markdown) ? ['contents' => ['kind' => 'markdown', 'value' => $markdown]] : null;
    }

    /** The providers' tokens, encoded as LSP wants them: relative positions in UTF-16 units. */
    private function tokens(array $params): array
    {
        $at = $this->position($params + ['position' => ['line' => 0, 'character' => 0]]);
        $tokens = $at === null ? [] : (array) $this->ask('tokens', $at[0], $at[1]);
        usort($tokens, static fn (array $a, array $b) => [$a['line'] ?? 0, $a['column'] ?? 0] <=> [$b['line'] ?? 0, $b['column'] ?? 0]);
        $lines = $at === null ? [] : explode("\n", $at[1]);
        $data = [];
        [$line, $start, $end] = [0, 0, -1];
        foreach ($tokens as $token) {
            $type = array_search($token['type'] ?? '', TOKEN_TYPES, true);
            $text = $lines[(int) ($token['line'] ?? 0) - 1] ?? null;
            if ($type === false || $text === null) {
                continue;
            }
            $at16 = utf16(substr($text, 0, (int) ($token['column'] ?? 0)));
            $length = utf16(substr($text, (int) ($token['column'] ?? 0), (int) ($token['length'] ?? 0)));
            $tokenLine = (int) $token['line'] - 1;
            // Overlapping tokens: the first one wins
            if ($length === 0 || ($tokenLine === $line && $at16 < $end)) {
                continue;
            }
            array_push($data, $tokenLine - $line, $tokenLine === $line ? $at16 - $start : $at16, $length, $type, 0);
            [$line, $start, $end] = [$tokenLine, $at16, $at16 + $length];
        }
        return ['data' => $data];
    }

    private function definition(array $params): ?array
    {
        $at = $this->position($params);
        $place = $at === null ? null : $this->ask('definition', ...$at);
        if (!is_array($place) || !is_string($place['file'] ?? null)) {
            return null;
        }
        $line = max(0, (int) ($place['line'] ?? 1) - 1);
        return ['uri' => uri($place['file']), 'range' => ['start' => ['line' => $line, 'character' => 0], 'end' => ['line' => $line, 'character' => 0]]];
    }
}

/** The process that loads the project: one JSON line per question and per answer. */
final class Worker
{
    /** @var resource */
    private $process;

    /** @var array<int, resource> */
    private array $pipes = [];

    private bool $alive = true;

    public function __construct(string $binary, string $root)
    {
        $process = proc_open([$binary, 'lsp', '--worker', $root], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => fopen('php://stderr', 'wb')], $this->pipes, $root);
        if ($process === false) {
            throw new \RuntimeException("the worker does not start in $root");
        }
        $this->process = $process;
    }

    public function alive(): bool
    {
        return $this->alive;
    }

    public function ask(string $method, array $args): mixed
    {
        if (!$this->alive) {
            return null;
        }
        fwrite($this->pipes[0], encode(['m' => $method, 'a' => $args]) . "\n");
        fflush($this->pipes[0]);
        $read = [$this->pipes[1]];
        $none = null;
        // A provider in an endless loop must not hang the editor
        if (stream_select($read, $none, $none, ANSWER_SECONDS) !== 1 || ($line = fgets($this->pipes[1])) === false) {
            log("the worker gave no answer to $method, starting a new one");
            $this->alive = false;
            return null;
        }
        $answer = json_decode($line, true);
        return is_array($answer) ? $answer['r'] ?? null : null;
    }

    public function stop(): void
    {
        foreach ($this->pipes as $pipe) {
            fclose($pipe);
        }
        proc_terminate($this->process);
        proc_close($this->process);
    }
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
                    log(var_export($class, true) . ' is no Tenma\Lsp\Provider');
                }
            } catch (\Throwable $e) {
                log("$class: " . $e->getMessage());
            }
        }
    }
    return $providers;
}

/** The worker's answers: what the providers say, and `$this->` from the class they name. */
final class Answers
{
    /** @param list<Provider> $providers */
    public function __construct(private readonly array $providers)
    {
    }

    public function answer(string $method, array $args): mixed
    {
        return match ($method) {
            'completions' => $this->completions(...$args),
            'definition' => $this->definition(...$args),
            'hover' => $this->hover(...$args),
            'diagnostics' => $this->collect(static fn (Provider $p) => $p->diagnostics(...$args)),
            'tokens' => $this->collect(static fn (Provider $p) => $p instanceof \Tenma\Lsp\Tokens ? $p->tokens(...$args) : []),
            default => null,
        };
    }

    private function completions(string $file, string $text, int $offset): array
    {
        $items = $this->collect(static fn (Provider $p) => $p->completions($file, $text, $offset));
        $before = substr($text, 0, $offset);
        $types = $this->types($file, $text);
        if (preg_match('/\$(\w+)((?:->\w+(?:\([^()]*\))?)*)->\w*$/', $before, $m) === 1) {
            $root = $this->variable($file, $text, $before, $m[1]);
            $target = $root === null ? null : $this->follow($root, $m[2]);
            if ($target !== null) {
                array_push($items, ...self::members($target, $target->getName() === ($types['this'] ?? null)));
            }
        }
        if (preg_match('/(?<![\w\\\\$>:])(\\\\?[A-Za-z_][\w\\\\]*)::\$?\w*$/', $before, $m) === 1 && $types !== []) {
            $class = $this->resolve($m[1], $file, $text);
            if ($class !== null) {
                array_push($items, ...self::statics(new \ReflectionClass($class), $class === ($types['this'] ?? null)));
            }
        }
        return $items;
    }

    private function definition(string $file, string $text, int $offset): ?array
    {
        foreach ($this->providers as $provider) {
            $place = $this->guard(static fn () => $provider->definition($file, $text, $offset));
            if (is_array($place)) {
                return $place;
            }
        }
        $member = $this->member($file, $text, $offset);
        if ($member instanceof \ReflectionMethod) {
            return ['file' => (string) $member->getFileName(), 'line' => (int) $member->getStartLine()];
        }
        if ($member instanceof \ReflectionProperty) {
            $owner = self::propertyOwner($member);
            return ['file' => (string) $owner->getFileName(), 'line' => self::propertyLine($owner, $member->getName())];
        }
        $class = $this->classAt($file, $text, $offset);
        if ($class !== null) {
            return ['file' => (string) $class->getFileName(), 'line' => (int) $class->getStartLine()];
        }
        // In a class file the editor's PHP server knows the variable; a template's @foreach or assignment it does not
        return $this->types($file, $text) === [] ? null : self::introduction($file, $text, $offset);
    }

    /**
     * For a plain `$name` at $offset: the last foreach (as value or key) or assignment above that introduces it,
     * not one in a comment or string.
     */
    private static function introduction(string $file, string $text, int $offset): ?array
    {
        preg_match('/^\$?\w*/', substr($text, $offset), $rest);
        $before = substr($text, 0, $offset);
        if (preg_match('/\$(\w*)$/', $before . $rest[0], $m) !== 1 || $m[1] === '' || $m[1] === 'this') {
            return null;
        }
        $at = $offset + strlen($rest[0]);
        $name = preg_quote($m[1], '/');
        $pattern = '/\bas\s+(?:\$\w+\s*=>\s*)?&?\$' . $name . '\b|\bas\s+&?\$' . $name . '\s*=>|\$' . $name . '\b\s*=(?![=>])/';
        if (preg_match_all($pattern, self::code(substr($text, 0, $at)), $found, PREG_OFFSET_CAPTURE) === 0) {
            return null;
        }
        return ['file' => $file, 'line' => substr_count($text, "\n", 0, end($found[0])[1]) + 1];
    }

    /** Comments and strings of the PHP parts blanked out, offsets kept; a template's own syntax is inline HTML. */
    private static function code(string $text): string
    {
        $code = '';
        foreach (token_get_all($text) as $token) {
            $hidden = is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true);
            $code .= $hidden ? (string) preg_replace('/[^\n]/', ' ', $token[1]) : (is_array($token) ? $token[1] : $token);
        }
        return $code;
    }

    private function hover(string $file, string $text, int $offset): ?string
    {
        $member = $this->member($file, $text, $offset) ?? $this->classAt($file, $text, $offset);
        if ($member === null) {
            return $this->typeAt($file, $text, $offset);
        }
        $doc = trim((string) preg_replace('#^\s*/\*\*|\*/\s*$|^\s*\* ?#m', '', (string) $member->getDocComment()));
        $head = $member instanceof \ReflectionClass ? self::kind($member) . ' ' . $member->getName() : self::signature($member);
        return "```php\n$head\n```" . ($doc !== '' ? "\n\n$doc" : '');
    }

    /** The hover of a plain `$name` at $offset that has a class: the class and the name. */
    private function typeAt(string $file, string $text, int $offset): ?string
    {
        preg_match('/^\w*/', substr($text, $offset), $rest);
        if (preg_match('/\$(\w+)$/', substr($text, 0, $offset) . $rest[0], $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        $class = $this->variable($file, $text, substr($text, 0, $m[0][1]), $m[1][0]);
        return $class === null ? null : "```php\n{$class->getName()} \${$m[1][0]}\n```";
    }

    /**
     * In a file a provider binds: the class named at $offset where PHP code names one, before `::` or after
     * `new` or `instanceof`; plain words of the template stay words.
     */
    private function classAt(string $file, string $text, int $offset): ?\ReflectionClass
    {
        preg_match('/[\w\\\\]*$/', substr($text, 0, $offset), $head);
        preg_match('/^[\w\\\\]*/', substr($text, $offset), $tail);
        $name = $head[0] . $tail[0];
        $before = substr($text, 0, $offset - strlen($head[0]));
        $after = substr($text, $offset + strlen($tail[0]));
        if (preg_match('/^\\\\?[A-Za-z_]/', $name) !== 1 || preg_match('/(?:\$|->|::)$/', $before) === 1
            || (preg_match('/^\s*::/', $after) !== 1 && preg_match('/\b(?:new|instanceof)\s+$/', $before) !== 1)
            || $this->types($file, $text) === []) {
            return null;
        }
        $class = $this->resolve($name, $file, $text);
        return $class === null ? null : new \ReflectionClass($class);
    }

    private static function kind(\ReflectionClass $class): string
    {
        return match (true) {
            $class->isEnum() => 'enum',
            $class->isInterface() => 'interface',
            $class->isTrait() => 'trait',
            default => ltrim(($class->isFinal() ? 'final ' : '') . ($class->isAbstract() ? 'abstract ' : '') . 'class'),
        };
    }

    /** The method or property named at $offset in a `$this->a->b()->c` chain. */
    private function member(string $file, string $text, int $offset): \ReflectionMethod|\ReflectionProperty|null
    {
        preg_match('/^\w*/', substr($text, $offset), $rest);
        $before = substr($text, 0, $offset);
        if (preg_match('/\$(\w+)((?:->\w+(?:\([^()]*\))?)*)->(\w*)$/', $before . $rest[0], $m) !== 1 || $m[3] === '') {
            return null;
        }
        $root = $this->variable($file, $text, $before, $m[1]);
        $target = $root === null ? null : $this->follow($root, $m[2]);
        return match (true) {
            $target === null => null,
            $target->hasMethod($m[3]) => $target->getMethod($m[3]),
            $target->hasProperty($m[3]) => $target->getProperty($m[3]),
            default => null,
        };
    }

    private function thisClass(string $file): ?string
    {
        foreach ($this->providers as $provider) {
            $class = $this->guard(static fn () => $provider->thisClass($file));
            if (is_string($class) && class_exists($class)) {
                return $class;
            }
        }
        return null;
    }

    /**
     * @return array<string, string> The variables the providers type in $file, by name: `$this` from thisClass(),
     *     the others from Tenma\Lsp\Variables (the first provider wins); empty in a file no provider binds
     */
    private function types(string $file, string $text): array
    {
        $types = [];
        $bound = $this->thisClass($file);
        if ($bound !== null) {
            $types['this'] = $bound;
        }
        foreach ($this->providers as $provider) {
            $given = $provider instanceof \Tenma\Lsp\Variables ? $this->guard(static fn () => $provider->variables($file, $text)) : null;
            foreach (is_array($given) ? $given : [] as $name => $class) {
                $class = is_string($class) ? trim($class, '\\') : '';
                if (class_exists($class) || interface_exists($class)) {
                    $types[ltrim((string) $name, '$')] ??= $class;
                }
            }
        }
        return $types;
    }

    /**
     * The class of `$name` in the text before the cursor. `$this` is the class a provider binds, and a variable
     * a provider types (Tenma\Lsp\Variables) has that class unless the file introduces it above. In a file a
     * provider binds, another variable gets its type from the last foreach or assignment above that introduces
     * it: a foreach over `$this->items` (or another such variable) gives the element type of `items`, from its
     * PHPDoc (`list<Item>`, `array<int, Item>`, `Item[]`); `$name = $this->item;`, `$name = $this->item();` and
     * `$name = new Item(...);` give the declared type.
     */
    private function variable(string $file, string $text, string $before, string $name, int $depth = 0): ?\ReflectionClass
    {
        $types = $this->types($file, $text);
        if ($name === 'this') {
            return isset($types['this']) ? new \ReflectionClass($types['this']) : null;
        }
        if ($depth > 3 || $types === []) {
            return null;
        }
        $chain = '\$(\w+)((?:->\w+(?:\([^()]*\))?)*)';
        $n = preg_quote($name, '/');
        $pattern = '/foreach\s*\(\s*' . $chain . '\s+as\s+(?:\$\w+\s*=>\s*)?&?\$' . $n . '\s*\)'
            . '|\$' . $n . '\b\s*=(?![=>])\s*(?:new\s+(\\\\?[\w\\\\]+)\s*(?:\([^()]*\))?\s*(?:;|\?>)|' . $chain . '\s*(?:;|\?>))?/';
        $code = self::code($before);
        if (preg_match_all($pattern, $code, $found, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL) === 0) {
            return isset($types[$name]) ? new \ReflectionClass($types[$name]) : null;
        }
        $last = end($found);
        $above = substr($code, 0, $last[0][1]);
        if ($last[1][0] !== null) {
            // A foreach: the element type of the last member in the chain
            if (preg_match('/^(.*)->(\w+)(?:\([^()]*\))?$/', $last[2][0], $split) !== 1) {
                return null;
            }
            $root = $this->variable($file, $text, $above, $last[1][0], $depth + 1);
            $owner = $root === null ? null : $this->follow($root, $split[1]);
            $member = match (true) {
                $owner === null => null,
                $owner->hasProperty($split[2]) => $owner->getProperty($split[2]),
                $owner->hasMethod($split[2]) => $owner->getMethod($split[2]),
                default => null,
            };
            $element = $member === null ? null : $this->elementClass($member);
            return $element === null ? null : new \ReflectionClass($element);
        }
        if ($last[3][0] !== null) {
            $class = $this->resolve($last[3][0], $file, $text);
            return $class === null ? null : new \ReflectionClass($class);
        }
        // An assignment of something else (a string, a sum) leaves no class
        $root = $last[4][0] === null ? null : $this->variable($file, $text, $above, $last[4][0], $depth + 1);
        return $root === null ? null : $this->follow($root, (string) $last[5][0]);
    }

    /** The class of the elements a property or method holds, from `@var` or `@return`. */
    private function elementClass(\ReflectionProperty|\ReflectionMethod $member): ?string
    {
        $tag = $member instanceof \ReflectionProperty ? '@var' : '@return';
        if (preg_match('/' . $tag . '\s+((?:[^\s<>{}]|<(?:[^<>]|<[^<>]*>)*>|\{[^}]*\})+)/', (string) $member->getDocComment(), $m) !== 1) {
            return null;
        }
        foreach (explode('|', ltrim($m[1], '?')) as $type) {
            $element = match (true) {
                str_ends_with($type, '[]') => substr($type, 0, -2),
                preg_match('/^[\w\\\\-]+<(.*)>$/', $type, $generic) === 1 => trim((string) preg_replace('/^(?:[^,<]|<[^>]*>)*,/', '', $generic[1])),
                default => null,
            };
            $file = (string) $member->getDeclaringClass()->getFileName();
            $class = $element === null ? null : $this->resolve($element, $file, (string) @file_get_contents($file));
            if ($class !== null) {
                return $class;
            }
        }
        return null;
    }

    /** A class name written in $file, resolved like PHP does: `use` statements, then the namespace. */
    private function resolve(string $name, string $file, string $text): ?string
    {
        if (str_starts_with($name, '\\')) {
            $class = substr($name, 1);
        } else {
            [$first] = explode('\\', $name, 2);
            $imports = $this->imports($file, $text);
            $imported = $imports[strtolower($first)] ?? null;
            $namespace = $imports[''] ?? '';
            $class = $imported !== null ? $imported . substr($name, strlen($first)) : ($namespace !== '' ? "$namespace\\$name" : $name);
        }
        return class_exists($class) || interface_exists($class) ? $class : null;
    }

    /**
     * @return array<string, string> The names a file sees, by lower-case alias, its namespace under '': its own `use`
     *     statements and namespace, and for what it has not, the providers' Tenma\Lsp\Imports (the first one wins)
     */
    private function imports(string $file, string $text): array
    {
        $imports = self::own($text);
        foreach ($this->providers as $provider) {
            $given = $provider instanceof \Tenma\Lsp\Imports ? $this->guard(static fn () => $provider->imports($file, $text)) : null;
            foreach (is_array($given) ? $given : [] as $alias => $class) {
                if (is_string($class)) {
                    $imports[strtolower((string) $alias)] ??= trim($class, '\\');
                }
            }
        }
        return $imports;
    }

    /** @var array<string, array<string, string>> hash of a text => its imports */
    private static array $own = [];

    /** @return array<string, string> The `use` statements of a text by lower-case alias, its namespace under '' */
    private static function own(string $text): array
    {
        $key = md5($text);
        if (isset(self::$own[$key])) {
            return self::$own[$key];
        }
        // Every keystroke is a new text
        if (count(self::$own) > 100) {
            self::$own = [];
        }
        $imports = [];
        // ponytail: one namespace per file and no function or const imports, as PSR-4 files are
        // The code up to the first declaration, without comments: "class" in a comment is no declaration
        $code = '';
        foreach (token_get_all($text) as $token) {
            if (is_array($token) && in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                break;
            }
            $code .= is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : (is_array($token) ? $token[1] : $token);
        }
        if (preg_match('/\bnamespace\s+([\w\\\\]+)\s*[;{]/', $code, $namespace) === 1) {
            $imports[''] = trim($namespace[1], '\\');
        }
        preg_match_all('/\buse\s+([\w\\\\]+)(?:\s*\{([^}]*)\}|\s+as\s+(\w+))?\s*;/', $code, $uses, PREG_SET_ORDER);
        foreach ($uses as $use) {
            $prefix = trim($use[1], '\\');
            foreach (isset($use[2]) && $use[2] !== '' ? explode(',', $use[2]) : [''] as $part) {
                $part = trim($part);
                [$name, $alias] = preg_match('/^([\w\\\\]+)\s+as\s+(\w+)$/', $part, $as) === 1 ? [$as[1], $as[2]] : [$part, null];
                $class = $name === '' ? $prefix : "$prefix\\$name";
                $imports[strtolower($alias ?? ($use[3] ?? '') ?: substr((string) strrchr('\\' . $class, '\\'), 1))] = $class;
            }
        }
        return self::$own[$key] = $imports;
    }

    /** The class at the end of `->a->b` (or `->a()`): the declared types of properties and of methods' returns. */
    private function follow(\ReflectionClass $target, string $chain): ?\ReflectionClass
    {
        foreach (array_filter(explode('->', (string) preg_replace('/\([^()]*\)/', '', $chain))) as $name) {
            $type = match (true) {
                $target->hasProperty($name) => $target->getProperty($name)->getType(),
                $target->hasMethod($name) => $target->getMethod($name)->getReturnType(),
                default => null,
            };
            if (!$type instanceof \ReflectionNamedType || ($type->getName() !== 'self' && $type->getName() !== 'static' && ($type->isBuiltin() || !class_exists($type->getName())))) {
                return null;
            }
            $target = in_array($type->getName(), ['self', 'static'], true) ? $target : new \ReflectionClass($type->getName());
        }
        return $target;
    }

    /** @return list<array{label: string, kind: string, detail: string}> */
    private static function members(\ReflectionClass $class, bool $inside): array
    {
        $items = [];
        foreach ($class->getProperties() as $property) {
            if (!$property->isStatic() && ($inside || $property->isPublic())) {
                $items[] = ['label' => $property->getName(), 'kind' => 'property', 'detail' => self::signature($property)];
            }
        }
        foreach ($class->getMethods() as $method) {
            if (!$method->isStatic() && !str_starts_with($method->getName(), '__') && ($inside || $method->isPublic())) {
                $items[] = ['label' => $method->getName(), 'kind' => 'method', 'detail' => self::signature($method)];
            }
        }
        return $items;
    }

    /** @return list<array{label: string, kind: string, detail: string}> What `Name::` offers: cases, constants, statics */
    private static function statics(\ReflectionClass $class, bool $inside): array
    {
        $items = [];
        foreach ($class->getReflectionConstants() as $constant) {
            if ($inside || $constant->isPublic()) {
                $items[] = ['label' => $constant->getName(), 'kind' => 'value', 'detail' => ($constant->isEnumCase() ? 'case ' : 'const ') . $constant->getName()];
            }
        }
        foreach ($class->getProperties(\ReflectionProperty::IS_STATIC) as $property) {
            if ($inside || $property->isPublic()) {
                $items[] = ['label' => '$' . $property->getName(), 'kind' => 'property', 'detail' => self::signature($property)];
            }
        }
        foreach ($class->getMethods(\ReflectionMethod::IS_STATIC) as $method) {
            if ($inside || $method->isPublic()) {
                $items[] = ['label' => $method->getName(), 'kind' => 'method', 'detail' => self::signature($method)];
            }
        }
        return $items;
    }

    private static function signature(\ReflectionMethod|\ReflectionProperty $member): string
    {
        $modifiers = implode(' ', \Reflection::getModifierNames($member->getModifiers()));
        if ($member instanceof \ReflectionProperty) {
            return trim("$modifiers {$member->getType()} \${$member->getName()}");
        }
        $parameters = array_map(static fn (\ReflectionParameter $p) => trim("{$p->getType()} " . ($p->isVariadic() ? '...' : '') . "\${$p->getName()}"), $member->getParameters());
        $return = $member->getReturnType();
        return "$modifiers function {$member->getName()}(" . implode(', ', $parameters) . ')' . ($return !== null ? ": $return" : '');
    }

    /** Where a property is written: getDeclaringClass() names the class that uses a trait, not the trait. */
    private static function propertyOwner(\ReflectionProperty $property): \ReflectionClass
    {
        $owner = $property->getDeclaringClass();
        do {
            $traits = array_filter($owner->getTraits(), static fn (\ReflectionClass $trait) => $trait->hasProperty($property->getName()));
            $owner = $traits ? reset($traits) : $owner;
        } while ($traits);
        return $owner;
    }

    /**
     * Reflection knows no line for a property: the first `$name` in the declaration of $class.
     *
     * ponytail: a method parameter of the same name above the property wins; good enough while properties come first
     */
    private static function propertyLine(\ReflectionClass $class, string $name): int
    {
        foreach (token_get_all((string) file_get_contents((string) $class->getFileName())) as $token) {
            if (is_array($token) && $token[0] === T_VARIABLE && $token[1] === '$' . $name && $token[2] >= $class->getStartLine() && $token[2] <= $class->getEndLine()) {
                return $token[2];
            }
        }
        return (int) $class->getStartLine();
    }

    /** Every provider's list, one after another; a failing provider is logged and left out. */
    private function collect(\Closure $ask): array
    {
        $all = [];
        foreach ($this->providers as $provider) {
            array_push($all, ...array_values((array) $this->guard(static fn () => $ask($provider))));
        }
        return $all;
    }

    private function guard(\Closure $work): mixed
    {
        try {
            return $work();
        } catch (\Throwable $e) {
            log($e::class . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            return null;
        }
    }
}
