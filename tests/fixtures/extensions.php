<?php
// For tests/http.rs: runs as an ordinary script and checks every built-in extension with a real call.
$checks = [
    'pdo_sqlite' => function () {
        $db = new PDO('sqlite::memory:');
        $db->exec('CREATE TABLE t (n INTEGER)');
        $db->exec('INSERT INTO t VALUES (41)');
        return $db->query('SELECT n + 1 FROM t')->fetchColumn() == 42;
    },
    'pdo_drivers' => fn () => !array_diff(['mysql', 'pgsql', 'sqlite'], PDO::getAvailableDrivers()),
    'mysqli' => fn () => class_exists(mysqli::class),
    'pgsql' => fn () => function_exists('pg_connect'),
    'sqlite3' => fn () => (new SQLite3(':memory:'))->querySingle('SELECT 6 * 7') === 42,
    'openssl' => fn () => strlen(openssl_random_pseudo_bytes(16)) === 16 && in_array('https', stream_get_wrappers(), true),
    'curl' => fn () => is_array(curl_version()),
    'sodium' => fn () => strlen(sodium_crypto_box_keypair()) === SODIUM_CRYPTO_BOX_KEYPAIRBYTES,
    'intl' => fn () => (new NumberFormatter('de_DE', NumberFormatter::DECIMAL))->format(1234.5) === '1.234,5',
    'bcmath' => fn () => bcadd('0.1', '0.2', 1) === '0.3',
    'gmp' => fn () => gmp_strval(gmp_pow(2, 100)) === '1267650600228229401496703205376',
    'gd' => function () {
        ob_start();
        imagepng(imagecreatetruecolor(4, 4));
        return str_starts_with(ob_get_clean(), "\x89PNG");
    },
    'zip' => fn () => class_exists(ZipArchive::class),
    'mbstring' => fn () => mb_strtoupper("gr\u{f6}\u{df}e") === "GR\u{d6}SSE",
    'sockets' => fn () => function_exists('socket_create'),
    'exif' => fn () => function_exists('exif_read_data'),
];
$failed = 0;
foreach ($checks as $name => $check) {
    try {
        $ok = $check();
    } catch (Throwable $error) {
        $ok = false;
        echo "$name: {$error->getMessage()}\n";
    }
    echo ($ok ? 'ok ' : 'MISSING ') . "$name\n";
    $failed += $ok ? 0 : 1;
}
exit($failed ? 1 : 0);
