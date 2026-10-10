<?php
// For tests/http.rs: php://input, uploads and exit() in classic mode
Tenma::serveClassic((int) getenv('PORT'), function () {
    switch (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) {
        case '/input':
            echo 'input: ' . file_get_contents('php://input');
            break;
        case '/upload':
            $file = $_FILES['file'];
            $target = sys_get_temp_dir() . '/tenma-upload-' . getmypid();
            $moved = move_uploaded_file($file['tmp_name'], $target);
            echo "{$_POST['title']} {$file['name']} {$file['size']} " . ($moved ? file_get_contents($target) : 'not moved');
            unlink($target);
            break;
        case '/exit':
            header('X-Before-Exit: yes');
            echo 'before exit';
            exit;
        case '/flush-all':
            // Like Symfony Response::send() or WordPress: flush all output buffers
            ob_start();
            echo 'a';
            ob_start();
            echo 'b';
            while (ob_get_level() > 0 && ob_end_flush()) {
            }
            echo 'c';
            break;
        case '/locked-buffer':
            // A buffer that cannot be removed must not hang the worker
            ob_start(null, 0, PHP_OUTPUT_HANDLER_CLEANABLE | PHP_OUTPUT_HANDLER_FLUSHABLE);
            echo 'inside ';
            while (ob_get_level() > 0 && @ob_end_flush()) {
            }
            echo 'outside';
            break;
        case '/async':
            // Rejected in classic mode before the op starts
            try {
                Tenma::sleep(0.01);
            } catch (LogicException) {
                echo 'rejected';
            }
            try {
                Tenma::async(fn () => null);
            } catch (LogicException) {
                echo ' async too';
            }
            break;
        case '/warn':
            // The warning belongs in the log on stderr, never in the response
            echo 'only this' . $doesNotExist;
            break;
        default:
            echo 'ok';
    }
});
