<?php
// Line numbers matter: tests/http.rs sets breakpoints on them
final class Item {
    public function __construct(public int $count = 1, private array $tags = ['a' => 1], public readonly string $id = 'x') {}
    public function tags(): array { return $this->tags; }
}
class NotFound extends RuntimeException {}

Tenma::serve((int) getenv('PORT'), function ($request) {
    if ($request->path === '/loop') {
        $total = 0;
        for ($i = 1; $i <= 10; $i++) {
            $total += $i;
        }
        return (string) $total;
    }
    if ($request->path === '/throw') {
        try {
            throw new LogicException('logic');
        } catch (LogicException) {
        }
        throw new NotFound('missing');
    }
    $list = ['x' => ['y' => 1]];
    $shared = $list;
    $item = new Item();
    Tenma::debugger();
    return json_encode([$list, $shared, $item->count, $item->tags()]);
});
