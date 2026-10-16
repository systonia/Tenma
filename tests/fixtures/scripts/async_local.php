<?php
// For tests/http.rs: Tenma\AsyncLocal travels with tasks and timers
$id = new Tenma\AsyncLocal();
$id->set('script');

$request = function (string $name) use ($id): Tenma\Task {
    return Tenma::async(function () use ($id, $name) {
        $id->set($name);
        $child = Tenma::async(function () use ($id) {
            Tenma::sleep(0.02);
            $seen = $id->get();
            $id->set('child-own');
            return $seen;
        });
        Tenma::setTimeout(fn () => print("timer $name: {$id->get()}\n"), 0.01);
        $detached = Tenma::detach(function () use ($id) {
            Tenma::sleep(0.03);
            return $id->get();
        });
        Tenma::sleep(0.01);
        // End of the request: its own value goes, children keep their copy
        $id->set(null);
        return [$name, $child->await(), $detached->await(), $id->get()];
    });
};

Tenma::async(function () use ($request, $id) {
    // Two interleaved "requests": each sees only its own value, the child does not write back
    [$a, $b] = Tenma::all($request('a'), $request('b'));
    echo json_encode([$a, $b, $id->get()]), "\n";
});
