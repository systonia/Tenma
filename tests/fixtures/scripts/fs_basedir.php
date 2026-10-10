<?php
// For tests/http.rs: open_basedir also applies to Tenma\Fs
Tenma::async(function () {
    try {
        Tenma\Fs::readFile('/etc/passwd');
        echo "read\n";
    } catch (RuntimeException $error) {
        echo str_contains($error->getMessage(), 'open_basedir') ? "forbidden\n" : $error->getMessage();
    }
});
