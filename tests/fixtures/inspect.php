<?php
// Line numbers matter: tests/http.rs sets breakpoints on them
function greet(string $name): string {
    $text = "Hello $name";
    return $text;
}

Tenma::serve((int) getenv('PORT'), function ($request) {
    $id = 42;
    $message = greet('world');
    Tenma::sleep(0.01);
    $after = 'slept';
    return "$message $after";
});
