<?php

namespace Tenma\Http;

/** @internal Splits multipart/form-data into fields and files, following the rules of PHP's rfc1867 parser. */
final class Multipart
{
    /** Like max_input_vars: more parts are an attack or a bug, not forms. */
    private const MAX_PARTS = 1000;

    /** @return array{array<string, mixed>, array<string, mixed>} */
    public static function parse(string $body, string $boundary): array
    {
        // Like PHP: lines end with CRLF or just LF. With a limit, so a hostile body does not first create
        // millions of parts; the rest then ends up in the last element
        $parts = preg_split('/\r?\n--' . preg_quote($boundary, '/') . '/', "\n" . $body, self::MAX_PARTS + 2);
        // Nothing useful comes before the first boundary; nor after the last one (--)
        array_shift($parts);
        // The closing boundary (--) is no part of its own
        if (count($parts) > self::MAX_PARTS && !(count($parts) === self::MAX_PARTS + 1 && str_starts_with(end($parts), '--'))) {
            throw new \RuntimeException('Too many parts in the form');
        }
        $fields = [];
        $values = [];
        $paths = [];
        $uploads = [];
        foreach ($parts as $part) {
            if (str_starts_with($part, '--')) {
                break;
            }
            // The rest of the boundary line, then head and content, separated by an empty line
            if (!preg_match('/\A[^\n]*\n(.*?)\r?\n\r?\n/s', $part, $match)) {
                continue;
            }
            $head = $match[1];
            $content = substr($part, strlen($match[0]));
            $disposition = self::parameters(self::header($head, 'content-disposition') ?? '');
            $name = $disposition['name'] ?? null;
            if ($name === null) {
                continue;
            }
            $filename = $disposition['filename'] ?? null;
            if ($filename === null) {
                $values[] = $content;
                $fields[] = self::encodeName($name) . '=' . (count($values) - 1);
                continue;
            }
            if ($filename === '') {
                // A file field with nothing selected, as the browser sends it; PHP drops one with content too
                continue;
            }
            // Older clients send the full path, even with backslashes: only the name counts
            $uploads[] = new UploadedFile(basename(str_replace('\\', '/', $filename)), self::header($head, 'content-type') ?? 'application/octet-stream', $content);
            $paths[] = self::encodeName($name) . '=' . (count($uploads) - 1);
        }
        // Names go through PHP's own parser: a[] appends, a[b] nests, a.b becomes a_b, later ones
        // overwrite. It holds numbers first, then the values: a value never gets copied or encoded
        parse_str(implode('&', $fields), $parsed);
        parse_str(implode('&', $paths), $files);
        array_walk_recursive($parsed, function (mixed &$index) use ($values): void {
            $index = $values[(int) $index];
        });
        array_walk_recursive($files, function (mixed &$index) use ($uploads): void {
            $index = $uploads[(int) $index];
        });
        return [$parsed, $files];
    }

    private static function header(string $head, string $name): ?string
    {
        foreach (preg_split('/\r?\n/', $head) as $line) {
            [$key, $value] = explode(':', $line, 2) + [1 => ''];
            if (strcasecmp(trim($key), $name) === 0) {
                return trim($value);
            }
        }
        return null;
    }

    /**
     * The parameters of a header value like `form-data; name="x"; filename=y`, read from left to right
     * so that a ; inside quotes does not start a new parameter. Inside quotes, as in PHP, only \" and
     * \\ count as escapes; names in lowercase, the first one wins.
     *
     * @return array<string, string>
     */
    private static function parameters(string $value): array
    {
        preg_match_all('/;\s*([^\s=;]+)\s*=\s*(?:"((?:[^"\\\\]|\\\\.)*)"|([^;]*))/', $value, $matches, PREG_SET_ORDER);
        $parameters = [];
        foreach ($matches as $match) {
            $parameters[strtolower($match[1])] ??= isset($match[3]) ? trim($match[3]) : preg_replace('/\\\\(["\\\\])/', '$1', $match[2]);
        }
        return $parameters;
    }

    /** Field names for parse_str: everything encoded except the brackets, which nest. */
    private static function encodeName(string $name): string
    {
        return str_replace(['%5B', '%5D'], ['[', ']'], rawurlencode($name));
    }
}
