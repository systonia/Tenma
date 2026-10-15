<?php

namespace Fixture;

use Tenma\Lsp\Provider;

/** The provider of an installed package: one completion in a text that asks for hints. */
final class Hints implements Provider
{
    public function thisClass(string $file): ?string
    {
        return null;
    }

    public function completions(string $file, string $text, int $offset): array
    {
        return str_contains($text, 'hints') ? [['label' => 'from-package']] : [];
    }

    public function definition(string $file, string $text, int $offset): ?array
    {
        return null;
    }

    public function diagnostics(string $file, string $text): array
    {
        return [];
    }
}
