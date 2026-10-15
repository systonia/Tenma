<?php
// For tests/http.rs: fibers with sleep and fetch
use Tenma\Http\Request;


$port = (int) getenv('PORT');
// An interval that runs in the background next to the requests
$ticks = 0;
// What requests left behind: their tasks, timers and subscriptions must end with the response
$left = ['interval' => 0, 'message' => 0, 'task' => 0, 'detached' => 0, 'retried' => 0, 'late' => 0];
Tenma::setInterval(function () use (&$ticks) {
    $ticks++;
}, 0.05);
Tenma::serve($port, function (Request $request) use ($port, &$ticks, &$left): string {
    switch ($request->path) {
        case '/slow':
            Tenma::sleep(0.5);
            return "slept enough\n";
        case '/proxy':
            // Asks the same server: would never work without fibers, it would be blocked
            return 'proxy: ' . Tenma::fetch("http://127.0.0.1:$port/hello")->body;
        case '/hang':
            Tenma::sleep(3);
            return "too late\n";
        case '/fetch-timeout':
            try {
                return Tenma::fetch("http://127.0.0.1:$port/hang", timeout: 0.2)->body;
            } catch (RuntimeException) {
                return "timeout\n";
            }
        case '/ticks':
            return "ticks $ticks\n";
        case '/leave-behind':
            // Cancelled once, it catches that and carries on: the end of the request still reaches it
            $retry = Tenma::async(function () use (&$left) {
                try {
                    Tenma::sleep(1);
                } catch (Tenma\Cancelled) {
                }
                try {
                    Tenma::sleep(0.05);
                    $left['retried']++;
                } catch (Tenma\Cancelled) {
                    // Started in its cleanup after the request ended: ends at its first wait
                    Tenma::async(function () use (&$left) { Tenma::sleep(0.05); $left['late']++; });
                }
            });
            $retry->cancel();
            Tenma::sleep(0.01);
            Tenma::setInterval(function () use (&$left) { $left['interval']++; }, 0.01);
            Tenma::subscribe('left', function () use (&$left) { $left['message']++; });
            Tenma::async(function () use (&$left) { Tenma::sleep(0.05); $left['task']++; });
            Tenma::detach(function () use (&$left) { Tenma::sleep(0.05); $left['detached']++; });
            return "started\n";
        case '/left':
            Tenma::publish('left', 'anyone?');
            Tenma::sleep(0.1);
            return json_encode($left) . "\n";
        case '/all':
            // Three waits of 0.3 s each at the same time, named results like Promise.all
            $slow = fn (string $name) => function () use ($name) {
                Tenma::sleep(0.3);
                return $name;
            };
            return json_encode(Tenma::all(a: $slow('one'), b: $slow('two'), c: Tenma::async($slow('three')))) . "\n";
        case '/all-error':
            try {
                Tenma::all(fn () => 'good', function () {
                    Tenma::sleep(0.05);
                    throw new RuntimeException('broken');
                });
            } catch (RuntimeException $error) {
                return "caught: {$error->getMessage()}\n";
            }
        case '/buffered':
            // A template that waits while it renders: its buffer stays its own, with a callback one inside
            $id = $request->query['id'] ?? '';
            ob_start();
            echo "<$id>";
            ob_start(fn (string $text): string => strtoupper($text));
            echo "inner $id";
            Tenma::sleep(0.05);
            ob_end_flush();
            Tenma::sleep(0.01);
            echo "</$id>";
            return ob_get_clean() . "\n";
        case '/buffer-left-open':
            // Ends with a buffer still open: it goes with the request, the next one starts without it
            ob_start();
            echo 'never sent';
            Tenma::sleep(0.01);
            return 'level ' . ob_get_level() . "\n";
        case '/level':
            return 'level ' . ob_get_level() . "\n";
        case '/nested':
            // A task that starts tasks itself, and a fetch to the same server in between
            $outer = Tenma::async(fn () => Tenma::all(fn () => Tenma::fetch("http://127.0.0.1:$port/hello")->body, function () {
                Tenma::sleep(0.1);
                return 'inner';
            }));
            return json_encode($outer->await()) . "\n";
        case '/exit-task':
            // exit() in a task ends the request, whoever awaits the task does not hang
            Tenma::async(function () {
                Tenma::sleep(0.05);
                exit;
            })->await();
            return "never\n";
        case '/self-await':
            $task = null;
            $task = Tenma::async(function () use (&$task) {
                Tenma::sleep(0.01);
                try {
                    return $task->await();
                } catch (LogicException) {
                    return "rejected\n";
                }
            });
            return $task->await();
        case '/all-fast':
            // The error comes at once, not only after the slow sibling task
            try {
                Tenma::all(function () {
                    Tenma::sleep(5);
                    return 'slow';
                }, function () {
                    Tenma::sleep(0.05);
                    throw new RuntimeException('failed fast');
                });
            } catch (RuntimeException $error) {
                return "{$error->getMessage()}\n";
            }
        case '/cancel-race':
            // A and B await X; A cancels B as soon as X is done: B must see the cancellation
            $x = Tenma::async(function () {
                Tenma::sleep(0.05);
                return 'x';
            });
            $b = null;
            $a = Tenma::async(function () use ($x, &$b) {
                $x->await();
                $b->cancel();
            });
            $b = Tenma::async(function () use ($x) {
                try {
                    $x->await();
                    Tenma::async(fn () => Tenma::sleep(0.05))->await();
                    return "b kept running\n";
                } catch (Tenma\Cancelled) {
                    return "b cancelled\n";
                }
            });
            $a->await();
            return $b->await();
        case '/cancel-all':
            $late = false;
            $task = Tenma::async(fn () => Tenma::all(fn () => Tenma::sleep(0.3), function () use (&$late) {
                Tenma::sleep(0.1);
                $late = true;
            }));
            Tenma::sleep(0.02);
            $task->cancel();
            try {
                $task->await();
            } catch (Tenma\Cancelled) {
            }
            Tenma::sleep(0.2);
            return $late ? "child kept running\n" : "children cancelled\n";
        case '/unreachable':
            return Tenma::fetch('http://127.0.0.1:1/')->body;
        default:
            return "hello\n";
    }
});
