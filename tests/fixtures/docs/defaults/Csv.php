<?php

namespace Acme;

/** Writes rows with `$csv->write('. ')` style calls. Second sentence. */
final class Csv
{
    public const string SEPARATOR = ',';

    public string $lineEnding = "\r\n";

    public function write(string $separator = self::SEPARATOR): void {}
}
