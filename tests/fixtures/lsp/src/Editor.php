<?php

namespace Fixture;

use Tenma\Lsp\{Imports, Provider, Tokens, Variables};

/**
 * Binds $this in page.tpl.php to Page, completes "data-on-" values, reports "TODO" lines, colours "@words", and gives
 * page.tpl.php the namespace Fixture and OrderStatus, and $model there a Cart.
 */
final class Editor implements Provider, Tokens, Imports, Variables
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
        if (str_contains($text, 'NAN')) {
            return [['label' => 'not-a-number', 'detail' => NAN]];
        }
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

    public function tokens(string $file, string $text): array
    {
        $tokens = [];
        foreach (explode("\n", $text) as $i => $line) {
            preg_match_all('/@\w+/', $line, $found, PREG_OFFSET_CAPTURE);
            foreach ($found[0] as [$word, $column]) {
                $tokens[] = ['line' => $i + 1, 'column' => $column, 'length' => strlen($word), 'type' => 'keyword'];
                // Overlaps the keyword: left out
                $tokens[] = ['line' => $i + 1, 'column' => $column + 1, 'length' => 1, 'type' => 'variable'];
            }
        }
        // Not in the order of the text, and one of no known type
        return [...array_reverse($tokens), ['line' => 1, 'column' => 0, 'length' => 1, 'type' => 'unknown']];
    }

    public function imports(string $file, string $text): array
    {
        return $file === $this->root . '/page.tpl.php' ? ['' => 'Fixture', 'OrderStatus' => 'Fixture\\Shop\\OrderStatus'] : [];
    }

    public function variables(string $file, string $text): array
    {
        // A class that does not exist types nothing
        return $file === $this->root . '/page.tpl.php' ? ['model' => Cart::class, '$gone' => 'Fixture\\Missing'] : [];
    }
}
