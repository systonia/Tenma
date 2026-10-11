<?php
// Line numbers matter: tests/http.rs sets breakpoints on them
function greet(string $name): string {
    $text = "Hello $name";
    return $text;
}

final class Point {
    public function __construct(public int $x, private array $tags = ['a' => 1]) {}
}

Tenma::serve((int) getenv('PORT'), function ($request) {
    if ($request->path === '/wait') {
        Tenma::sleep(5);
        return 'woke';
    }
    if ($request->path === '/throw') {
        throw new RuntimeException('boom');
    }
    if ($request->path === '/caught') {
        try {
            throw new LogicException('handled');
        } catch (LogicException) {
            return 'caught';
        }
    }
    $id = 42;
    $point = new Point(7);
    $message = greet('world');
    Tenma::sleep(0.01);
    $after = 'slept';
    return "$message $after";
});
