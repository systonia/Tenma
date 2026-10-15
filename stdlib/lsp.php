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
    try {
        while (($message = read($in)) !== null) {
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
            return null;
        }
        $body .= $chunk;
    }
    $message = json_decode($body, true);
    return is_array($message) ? $message : [];
}

function encode(mixed $value): string
{
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
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

    /** @param resource $out */
    public function __construct(private readonly string $binary, private $out)
    {
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
        unset($this->documents[$uri]);
        $this->send(['jsonrpc' => '2.0', 'method' => 'textDocument/publishDiagnostics', 'params' => ['uri' => $uri, 'diagnostics' => []]]);
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
        $this->send(['jsonrpc' => '2.0', 'method' => 'textDocument/publishDiagnostics', 'params' => ['uri' => $uri, 'diagnostics' => $diagnostics]]);
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
    while (($line = fgets($in)) !== false) {
        $question = json_decode($line, true);
        $result = null;
        try {
            $result = $answers->answer((string) ($question['m'] ?? ''), (array) ($question['a'] ?? []));
        } catch (\Throwable $e) {
            log(($question['m'] ?? '?') . ': ' . $e->getMessage());
        }
        fwrite($out, encode(['r' => $result]) . "\n");
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
            default => null,
        };
    }

    private function completions(string $file, string $text, int $offset): array
    {
        $items = $this->collect(static fn (Provider $p) => $p->completions($file, $text, $offset));
        $class = $this->thisClass($file);
        if ($class !== null && preg_match('/\$this((?:->\w+)*)->\w*$/', substr($text, 0, $offset), $m) === 1) {
            $target = $this->follow($class, $m[1]);
            if ($target !== null) {
                array_push($items, ...self::members($target, $target->getName() === $class));
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
            $file = (string) $member->getDeclaringClass()->getFileName();
            return ['file' => $file, 'line' => self::propertyLine($member)];
        }
        return null;
    }

    private function hover(string $file, string $text, int $offset): ?string
    {
        $member = $this->member($file, $text, $offset);
        if ($member === null) {
            return null;
        }
        $doc = trim((string) preg_replace('#^\s*/\*\*|\*/\s*$|^\s*\* ?#m', '', (string) $member->getDocComment()));
        return "```php\n" . self::signature($member) . "\n```" . ($doc !== '' ? "\n\n$doc" : '');
    }

    /** The method or property named at $offset in a `$this->a->b` chain. */
    private function member(string $file, string $text, int $offset): \ReflectionMethod|\ReflectionProperty|null
    {
        $class = $this->thisClass($file);
        preg_match('/^\w*/', substr($text, $offset), $rest);
        if ($class === null || preg_match('/\$this((?:->\w+)*)->(\w*)$/', substr($text, 0, $offset) . $rest[0], $m) !== 1 || $m[2] === '') {
            return null;
        }
        $target = $this->follow($class, $m[1]);
        return match (true) {
            $target === null => null,
            $target->hasMethod($m[2]) => $target->getMethod($m[2]),
            $target->hasProperty($m[2]) => $target->getProperty($m[2]),
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

    /** The class at the end of `->a->b`: the declared types of properties and of methods' returns. */
    private function follow(string $class, string $chain): ?\ReflectionClass
    {
        $target = new \ReflectionClass($class);
        foreach (array_filter(explode('->', $chain)) as $name) {
            $type = match (true) {
                $target->hasProperty($name) => $target->getProperty($name)->getType(),
                $target->hasMethod($name) => $target->getMethod($name)->getReturnType(),
                default => null,
            };
            if (!$type instanceof \ReflectionNamedType || $type->isBuiltin() || !class_exists($type->getName())) {
                return null;
            }
            $target = new \ReflectionClass($type->getName());
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

    /**
     * Reflection knows no line for a property: the first `$name` in its class's declaration.
     *
     * ponytail: a method parameter of the same name above the property wins; good enough while properties come first
     */
    private static function propertyLine(\ReflectionProperty $property): int
    {
        $class = $property->getDeclaringClass();
        foreach (token_get_all((string) file_get_contents((string) $class->getFileName())) as $token) {
            if (is_array($token) && $token[0] === T_VARIABLE && $token[1] === '$' . $property->getName() && $token[2] >= $class->getStartLine() && $token[2] <= $class->getEndLine()) {
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
