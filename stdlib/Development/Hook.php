<?php

namespace Development;

/**
 * A build step for `tenma --watch` and `tenma build`, returned in a list from tenma.dev.php
 * next to the script. Runs once at startup and again
 * whenever a file matching $inputs changes. $run is PHP code or a command: a list of program
 * and arguments (an array is always a command) that Tenma starts as a process of its own,
 * without a shell, in the directory of tenma.dev.php.
 *
 * Tenma orders the hooks by their files: a hook reading what another one writes runs after it.
 */
final readonly class Hook
{
    /** @var \Closure(list<string>): mixed|list<string> */
    public \Closure|array $run;

    /**
     * @param list<string> $inputs globs relative to tenma.dev.php: * and ? within a name, ** across directories
     * @param (callable(list<string> $changed): mixed)|list<string> $run PHP code gets the changed paths, empty on the first run
     * @param list<string> $outputs files $run writes; they never trigger this hook again
     * @param ?string $name for log lines, default: the program's file name or "#N" (the position in the list)
     */
    public function __construct(
        public array $inputs,
        callable|array $run,
        public array $outputs = [],
        public ?string $name = null,
    ) {
        if (is_array($run) && ($run === [] || !array_is_list($run) || array_filter($run, fn ($part) => !is_string($part)))) {
            throw new \ValueError('A command is a non-empty list of strings: the program and its arguments');
        }
        $this->run = is_array($run) ? $run : $run(...);
    }
}
