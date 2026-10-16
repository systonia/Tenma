<?php

namespace Acme;

final class Csv
{
    public const string SEPARATOR = ',';

    public string $lineEnding = "\r\n";

    public function write(string $separator = self::SEPARATOR): void {}
}
