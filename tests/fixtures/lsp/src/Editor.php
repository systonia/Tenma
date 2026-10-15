<?php

namespace Fixture;

use Tenma\Lsp\Provider;

/** Binds $this in page.tpl.php to Page, completes "data-on-" values, reports "TODO" lines. */
final class Editor implements Provider
{
    public function __construct(private string $root)
    {
    }

    public function thisClass(string $file): ?string
    {
        return $file === $this->root . '/page.tpl.php' ? Page::class : null;
    }

    public function completions(string $file, string $text, int $offset): array
    {
        return str_ends_with(substr($text, 0, $offset), 'data-on-click="') ? [['label' => 'increment', 'kind' => 'method']] : [];
    }

    public function definition(string $file, string $text, int $offset): ?array
    {
        return str_contains($text, 'go-to-author') ? ['file' => __DIR__ . '/Author.php', 'line' => 5] : null;
    }

    public function diagnostics(string $file, string $text): array
    {
        if (str_contains($text, 'THROW')) {
            throw new \RuntimeException('a broken provider');
        }
        $problems = [];
        foreach (explode("\n", $text) as $i => $line) {
            if (str_contains($line, 'TODO')) {
                $problems[] = ['line' => $i + 1, 'message' => 'a TODO', 'severity' => 'warning'];
            }
        }
        return $problems;
    }
}
