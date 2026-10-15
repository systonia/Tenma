<?php
// Stands in for Composer's autoloader
spl_autoload_register(static function (string $class): void {
    $file = __DIR__ . '/../src/' . substr(strrchr('\\' . $class, '\\'), 1) . '.php';
    if (str_starts_with($class, 'Fixture\\') && is_file($file)) {
        require $file;
    }
});
echo "output of the project must not reach the protocol\n";
