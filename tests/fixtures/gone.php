<?php
// For tests/http.rs: if the client leaves before the response, the handler is cancelled
$marker = sys_get_temp_dir() . '/tenma-gone-' . getenv('PORT');
foreach (['', '-timer', '-detach'] as $suffix) {
    @unlink($marker . $suffix);
}
Tenma::serve((int) getenv('PORT'), function (Tenma\Http\Request $request) use ($marker) {
    switch ($request->path) {
        case '/status':
            $read = fn (string $suffix) => is_file($marker . $suffix) ? trim(file_get_contents($marker . $suffix)) : '-';
            return implode(' | ', [$read(''), $read('-timer'), $read('-detach')]) . "\n";
        case '/cancelled-task':
            // Someone else cancels the task the handler awaits: the client still gets a response
            $task = Tenma::async(fn () => Tenma::sleep(1));
            Tenma::setTimeout(fn () => $task->cancel(), 0.02);
            return $task->await();
    }
    // A timer of the request ends with it; detach() keeps running
    // Long enough that a slow machine notices the client leaving first
    Tenma::setTimeout(fn () => file_put_contents("$marker-timer", "timer ran\n"), 1.0);
    Tenma::detach(function () use ($marker) {
        Tenma::sleep(0.3);
        file_put_contents("$marker-detach", "detach ran\n");
    });
    $started = microtime(true);
    try {
        Tenma::sleep(3);
        file_put_contents($marker, "slept to the end\n");
    } catch (Tenma\Cancelled $error) {
        // Cleanup may still wait after the cancellation
        Tenma::sleep(0.01);
        file_put_contents($marker, sprintf("cancelled after %.1fs: %s\n", microtime(true) - $started, $error->getMessage()));
        throw $error;
    }
    return "done\n";
});
