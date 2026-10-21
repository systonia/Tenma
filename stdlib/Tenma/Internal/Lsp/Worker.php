<?php

namespace Tenma\Internal\Lsp;

use Lsp\Provider;

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
