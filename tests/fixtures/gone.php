<?php
// For tests/http.rs: if the client leaves before the response, the handler is cancelled
$marker = sys_get_temp_dir() . '/tenma-gone-' . getenv('PORT');
foreach (['', '-timer', '-detach'] as $suffix) {
    @unlink($marker . $suffix);
}
(new \Http\Server((int) getenv('PORT'), function (Http\Request $request) use ($marker) {
    switch ($request->path) {
        case '/status':
            $read = fn (string $suffix) => is_file($marker . $suffix) ? trim(file_get_contents($marker . $suffix)) : '-';
            return implode(' | ', [$read(''), $read('-timer'), $read('-detach')]) . "\n";
        case '/cancelled-task':
            // Someone else cancels the task the handler awaits: the client still gets a response
            $task = \Threading\Task::run(fn () => \Threading\Task::delay(1));
            \Threading\Timer::after(0.02, fn () => $task->cancel());
            return $task->await();
    }
    // A timer of the request ends with it; detach() keeps running
    // Long enough that a slow machine notices the client leaving first
    \Threading\Timer::after(1.0, fn () => file_put_contents("$marker-timer", "timer ran\n"));
    \Threading\Task::detach(function () use ($marker) {
        \Threading\Task::delay(0.3);
        file_put_contents("$marker-detach", "detach ran\n");
    });
    $started = microtime(true);
    try {
        \Threading\Task::delay(3);
        file_put_contents($marker, "slept to the end\n");
    } catch (Threading\Cancelled $error) {
        // Cleanup may still wait after the cancellation
        \Threading\Task::delay(0.01);
        file_put_contents($marker, sprintf("cancelled after %.1fs: %s\n", microtime(true) - $started, $error->getMessage()));
        throw $error;
    }
    return "done\n";
}))->start();
