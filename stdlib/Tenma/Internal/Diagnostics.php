<?php

namespace Tenma\Internal;

/** Problems::report(): who publishes, and the project's directory for `tenma lsp`. */
final class Diagnostics
{
    /** The script's workers ('app') or the hooks of tenma.dev.php ('dev'): --watch clears each on its reload */
    public static string $group = 'app';

    /** The nearest directory with a composer.json above $directory, or $directory itself. */
    public static function project(string $directory): string
    {
        for ($at = $directory; !is_file("$at/composer.json"); $at = dirname($at)) {
            if (dirname($at) === $at) {
                return $directory;
            }
        }
        return $at;
    }

    /** Where the lists of a project are: one JSON file per group and source, read by `tenma lsp`. */
    public static function directory(string $project): string
    {
        return "$project/.tenma/diagnostics";
    }
}
