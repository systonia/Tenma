<?php
// For tests/http.rs: Tenma\Fs in background work, everything in its own temp directory
use Tenma\Fs;

Tenma::async(function () {
    $dir = sys_get_temp_dir() . '/tenma-fs-' . getmypid();
    Fs::mkdir("$dir/sub/dir", recursive: true);
    Fs::writeFile("$dir/a.txt", "hello\0binary");
    Fs::appendFile("$dir/a.txt", ' and more');
    $read = Fs::readFile("$dir/a.txt");
    $copied = Fs::copy("$dir/a.txt", "$dir/b.txt");
    Fs::rename("$dir/b.txt", "$dir/sub/c.txt");
    $stat = Fs::stat("$dir/a.txt");
    echo json_encode([
        $read === "hello\0binary and more",
        $copied,
        Fs::readDir($dir),
        Fs::readDir("$dir/sub"),
        [$stat['size'], $stat['isFile'], $stat['isDir'], $stat['modified'] > 0],
        Fs::exists("$dir/a.txt"),
        Fs::exists("$dir/missing.txt"),
    ]), "\n";

    $failure = function (callable $call): string {
        try {
            $call();
            return 'no error';
        } catch (RuntimeException $error) {
            return $error->getCode() . ' ' . (str_contains($error->getMessage(), "tenma-fs-") ? 'with path' : $error->getMessage());
        }
    };
    // Missing: errno 2 (ENOENT); already there: 17 (EEXIST); a copy onto itself is rejected, the file stays
    echo $failure(fn () => Fs::readFile("$dir/missing.txt")), "\n";
    echo $failure(fn () => Fs::mkdir("$dir/sub")), "\n";
    echo $failure(fn () => Fs::copy("$dir/a.txt", "$dir/a.txt")), ' ', Fs::stat("$dir/a.txt")['size'], "\n";

    // A name that is not UTF-8 goes to the operating system unchanged: ext4 creates it, APFS rejects it
    // with EILSEQ (92). A mangled name would silently create caf\u{FFFD}.txt instead.
    $latin1 = "caf\xe9.txt";
    try {
        Fs::writeFile("$dir/$latin1", 'x');
        echo in_array($latin1, Fs::readDir($dir), true) && Fs::readFile("$dir/$latin1") === 'x' ? "bytes kept\n" : "mangled\n";
    } catch (RuntimeException $error) {
        echo $error->getCode() === 92 && Fs::readDir($dir) === ['a.txt', 'sub'] ? "bytes kept\n" : "mangled\n";
    }

    // Relative paths like file_get_contents: after chdir()
    chdir($dir);
    echo Fs::readFile('a.txt') === file_get_contents('a.txt') ? "chdir applies\n" : "chdir missing\n";

    Fs::remove("$dir/a.txt");
    Fs::removeDir($dir, recursive: true);
    echo Fs::exists($dir) ? "still there\n" : "cleaned up\n";
});
