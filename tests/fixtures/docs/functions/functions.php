<?php

namespace Acme\Text;

use Acme\Text\Style as Look;

/**
 * Joins CSS classes, each once. A string is always added; in an array, a string key is added when its value
 * is truthy.
 *
 * @param string|array<string, bool> ...$parts strings and arrays of class => condition
 * @return string the classes, separated by one space
 */
function classes(string|array ...$parts): string
{
    return '';
}

/** Styles a text. */
function styled(string $text, Look $look = Look::Plain): string
{
    $wrap = function (string $inner) {
        return $inner;
    };
    return $wrap($text);
}

/** @internal Not documented. */
function hidden(): void
{
}

enum Style
{
    case Plain;

    public function apply(string $text): string
    {
        return $text;
    }
}

namespace Acme\Text\Html;

/** Escapes for HTML. */
function &escape(string $text): string
{
    static $out = '';
    return $out;
}
