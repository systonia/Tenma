<?php
// For tests/http.rs: FileSystem\FileInfo and DirectoryInfo in background work, everything in its own temp directory
use FileSystem\{DirectoryInfo, FileInfo};

\Threading\Task::run(function () {
    $dir = sys_get_temp_dir() . '/tenma-fs-' . getmypid();
    (new DirectoryInfo("$dir/sub/dir"))->create(recursive: true);
    $a = new FileInfo("$dir/a.txt");
    $a->write("hello\0binary");
    $a->append(' and more');
    $read = $a->read();
    $copied = $a->copyTo("$dir/b.txt");
    $moved = $copied->moveTo("$dir/sub/c.txt");
    $names = fn (array $entries) => array_map(fn ($entry) => $entry->name(), $entries);
    echo json_encode([
        $read === "hello\0binary and more",
        $copied->path === "$dir/b.txt" && $moved->path === "$dir/sub/c.txt",
        $names((new DirectoryInfo($dir))->files()),
        $names((new DirectoryInfo($dir))->directories()),
        $names((new DirectoryInfo("$dir/sub"))->files('*.txt')),
        [$a->size(), $a->modified() > 0, (new DirectoryInfo($dir))->modified() > 0],
        $a->exists(),
        (new FileInfo("$dir/missing.txt"))->exists(),
        (new DirectoryInfo("$dir/missing"))->exists(),
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
    echo $failure(fn () => (new FileInfo("$dir/missing.txt"))->read()), "\n";
    echo $failure(fn () => (new DirectoryInfo("$dir/sub"))->create()), "\n";
    echo $failure(fn () => $a->copyTo("$dir/a.txt")), ' ', $a->size(), "\n";

    // A name that is not UTF-8 goes to the operating system unchanged: ext4 creates it, APFS rejects it
    // with EILSEQ (92). A mangled name would silently create caf\u{FFFD}.txt instead.
    $latin1 = "caf\xe9.txt";
    $listed = fn () => $names((new DirectoryInfo($dir))->files());
    try {
        (new FileInfo("$dir/$latin1"))->write('x');
        echo in_array($latin1, $listed(), true) && (new FileInfo("$dir/$latin1"))->read() === 'x' ? "bytes kept\n" : "mangled\n";
    } catch (RuntimeException $error) {
        echo $error->getCode() === 92 && $listed() === ['a.txt'] ? "bytes kept\n" : "mangled\n";
    }

    // Relative paths like file_get_contents: after chdir()
    chdir($dir);
    echo (new FileInfo('a.txt'))->read() === file_get_contents('a.txt') ? "chdir applies\n" : "chdir missing\n";

    $a->delete();
    (new DirectoryInfo($dir))->delete(recursive: true);
    echo (new DirectoryInfo($dir))->exists() ? "still there\n" : "cleaned up\n";
});
