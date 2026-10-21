<?php

namespace Tenma\Internal;

final class Dev
{
    public static string $directory;
    /** The list of hooks was invalid: nothing runs, `tenma build` fails */
    public static bool $failed = false;
    /** @var list<array{name: string, inputs: list<string>, roots: list<array{string, bool}>, outputs: list<string>, run: \Closure|list<string>, after: list<int>}> */
    public static array $hooks = [];
    /** @var list<int> Topological: a hook comes after the ones whose outputs it reads */
    public static array $order = [];
    /** @var array<string, ?string> Outputs and their hash after the hook that wrote them */
    public static array $hashes = [];
    /** @var array<int, array{float, float}> When a hook last ran, start and end, for the emergency brake */
    public static array $ran = [];
    /** @var array<int, int> Runs in a row that its own writing triggered */
    public static array $streak = [];
    /** @var array<int, true> Stopped by the emergency brake */
    public static array $stopped = [];

    public static function load(mixed $list): void
    {
        if (!is_array($list) || !array_is_list($list)) {
            throw new DevError('tenma.dev.php must return a list of Development\Hook, not ' . get_debug_type($list));
        }
        foreach ($list as $index => $hook) {
            if (!$hook instanceof \Development\Hook) {
                throw new DevError("tenma.dev.php: entry $index is " . get_debug_type($hook) . ', not a Development\Hook');
            }
            $inputs = array_map(self::absolute(...), $hook->inputs);
            self::$hooks[] = [
                'name' => $hook->name ?? (is_array($hook->run) ? basename($hook->run[0]) : '#' . ($index + 1)),
                'inputs' => array_map(self::regex(...), $inputs),
                'roots' => array_map(self::root(...), $inputs),
                'outputs' => array_map(self::absolute(...), $hook->outputs),
                'run' => $hook->run,
                'after' => [],
            ];
        }
        self::order();
    }

    private static function absolute(string $path): string
    {
        return str_starts_with($path, '/') ? $path : self::$directory . '/' . preg_replace('#^(\./)+#', '', $path);
    }

    private static function regex(string $glob): string
    {
        $parts = preg_split('#(\*\*/|\*\*|\*|\?)#', $glob, -1, PREG_SPLIT_DELIM_CAPTURE);
        return '#^' . implode('', array_map(fn ($part) => match ($part) {
            '**/' => '(?:.*/)?',
            '**' => '.*',
            '*' => '[^/]*',
            '?' => '[^/]',
            default => preg_quote($part, '#'),
        }, $parts)) . '$#';
    }

    /** The directory to watch for a glob: up to its first wildcard; recursive if one spans directories. */
    private static function root(string $glob): array
    {
        $parts = explode('/', $glob);
        $fixed = [];
        foreach ($parts as $part) {
            if (strpbrk($part, '*?') !== false) {
                break;
            }
            $fixed[] = $part;
        }
        if (count($fixed) === count($parts)) {
            return [dirname($glob), false];
        }
        $rest = implode('/', array_slice($parts, count($fixed)));
        return [implode('/', $fixed) ?: '/', str_contains($rest, '/') || str_contains($rest, '**')];
    }

    private static function matches(int $index, string $path): bool
    {
        foreach (self::$hooks[$index]['inputs'] as $regex) {
            if (preg_match($regex, $path)) {
                return true;
            }
        }
        return false;
    }

    /** Orders the hooks by their files and rejects cycles, before anything runs. */
    private static function order(): void
    {
        $count = count(self::$hooks);
        $needs = array_fill(0, $count, 0);
        $before = [];
        foreach (self::$hooks as $from => $hook) {
            foreach (array_keys(self::$hooks) as $to) {
                if ($from !== $to && array_filter($hook['outputs'], fn ($output) => self::matches($to, $output))) {
                    self::$hooks[$from]['after'][] = $to;
                    $before[$to][] = $from;
                    $needs[$to]++;
                }
            }
        }
        // Kahn's algorithm; among hooks that are ready, the list order decides
        $ready = array_keys(array_filter($needs, fn ($count) => $count === 0));
        while ($ready) {
            sort($ready);
            $index = array_shift($ready);
            self::$order[] = $index;
            foreach (self::$hooks[$index]['after'] as $next) {
                if (--$needs[$next] === 0) {
                    $ready[] = $next;
                }
            }
        }
        if (count(self::$order) === $count) {
            return;
        }
        // Every hook left waits for another one left: going backwards ends in a cycle
        $left = array_keys(array_filter($needs));
        $path = [];
        $index = $left[0];
        while (!in_array($index, $path, true)) {
            $path[] = $index;
            $index = current(array_filter($before[$index], fn ($from) => $needs[$from] > 0));
        }
        $cycle = array_reverse(array_slice($path, array_search($index, $path, true)));
        $names = array_map(fn ($index) => self::$hooks[$index]['name'], [...$cycle, $cycle[0]]);
        throw new DevError('hooks ' . implode(' → ', $names) . ' form a cycle (each reads what the other writes)');
    }

    public static function run(bool $watch): void
    {
        if ($watch && !self::$failed) {
            // Before the first run, so that changes during it are not lost; what the run writes
            // itself is no change (hashes)
            $roots = array_values(array_unique(array_merge(...array_column(self::$hooks, 'roots')), SORT_REGULAR));
            await(\tenma_op_dev('watch', json_encode(['roots' => $roots, 'directory' => self::$directory], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)));
        }
        $ok = !self::$failed && self::batch(array_fill_keys(self::$order, []));
        if (!$watch || self::$failed) {
            if (!$ok && !$watch) {
                exit(1);
            }
            return;
        }
        await(\tenma_op_dev('settled', ''));
        while (true) {
            // Path, modification time, path, …
            $fields = explode("\0", await(\tenma_op_dev('next', '')));
            $changed = [];
            for ($field = 0; $field + 1 < count($fields); $field += 2) {
                $changed[$fields[$field]] = (float) $fields[$field + 1];
            }
            $queue = [];
            foreach (self::$hooks as $index => $hook) {
                $paths = array_filter($changed, fn ($path) => !in_array($path, $hook['outputs'], true) && self::matches($index, $path) && !self::unchanged($path), ARRAY_FILTER_USE_KEY);
                if ($paths && self::allowed($index, $paths)) {
                    $queue[$index] = array_map('strval', array_keys($paths));
                }
            }
            self::batch($queue);
        }
    }

    /**
     * Emergency brake for a hook that writes files it does not declare as outputs into its own
     * inputs: stopped after five runs in a row that it triggered itself, i.e. by files written
     * while it ran. A change by someone else wakes it.
     *
     * @param array<string, float> $paths changed paths and their modification times
     */
    private static function allowed(int $index, array $paths): bool
    {
        [$start, $end] = self::$ran[$index] ?? [INF, -INF];
        $own = !array_filter($paths, fn ($modified) => $modified < $start - 0.05 || $modified > $end + 0.05);
        if (isset(self::$stopped[$index])) {
            if ($own) {
                return false;
            }
            unset(self::$stopped[$index]);
            self::$streak[$index] = 0;
            return true;
        }
        self::$streak[$index] = $own ? (self::$streak[$index] ?? 0) + 1 : 0;
        if (self::$streak[$index] < 5) {
            return true;
        }
        self::$stopped[$index] = true;
        error_log('tenma: hook ' . self::$hooks[$index]['name'] . ' keeps triggering itself, stopped (declare its outputs)');
        return false;
    }

    /** An output that still has the content its hook wrote. */
    private static function unchanged(string $path): bool
    {
        return array_key_exists($path, self::$hashes) && self::$hashes[$path] === self::hash($path);
    }

    private static function hash(string $path): ?string
    {
        return is_file($path) ? (hash_file('xxh128', $path) ?: null) : null;
    }

    /**
     * Runs the queued hooks in order. A hook whose outputs really changed queues the hooks
     * reading them; a failed one skips them. False if a hook failed.
     *
     * @param array<int, list<string>> $queue hook => changed paths
     */
    private static function batch(array $queue): bool
    {
        $ok = true;
        $skipped = [];
        foreach (self::$order as $index) {
            if (!isset($queue[$index])) {
                continue;
            }
            $hook = self::$hooks[$index];
            if (isset($skipped[$index])) {
                $skipped += array_fill_keys($hook['after'], true);
                continue;
            }
            $before = array_map(self::hash(...), $hook['outputs']);
            $start = microtime(true);
            $done = self::once($hook, $queue[$index]);
            self::$ran[$index] = [$start, microtime(true)];
            if (!$done) {
                $ok = false;
                $skipped += array_fill_keys($hook['after'], true);
                continue;
            }
            foreach ($hook['outputs'] as $position => $output) {
                self::$hashes[$output] = self::hash($output);
                if (self::$hashes[$output] === $before[$position]) {
                    continue;
                }
                foreach ($hook['after'] as $next) {
                    if (self::matches($next, $output)) {
                        $queue[$next] = [...$queue[$next] ?? [], $output];
                    }
                }
            }
        }
        return $ok;
    }

    private static function once(array $hook, array $changed): bool
    {
        try {
            if ($hook['run'] instanceof \Closure) {
                ($hook['run'])($changed);
                return true;
            }
            $command = ['name' => $hook['name'], 'command' => $hook['run'], 'directory' => self::$directory];
            $status = (int) await(\tenma_op_dev('run', json_encode($command, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)));
            if ($status !== 0) {
                throw new \RuntimeException("exited with status $status");
            }
            return true;
        } catch (\Throwable $error) {
            error_log("tenma: hook {$hook['name']} failed: " . ($hook['run'] instanceof \Closure ? $error : $error->getMessage()));
            return false;
        }
    }
}
