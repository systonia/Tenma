<?php
// For tests/http.rs: an SplFileObject on php://input that outlives its classic request. It holds the
// stream without a reference count, so tenma must not free the stream under it
(new \Http\ClassicServer((int) getenv('PORT'), function () {
    static $file = null;
    if ($file === null) {
        $file = new SplFileObject('php://input');
        echo $file->fgets();
        return;
    }
    $file->eof();
    echo "still here\n";
}))->start();
