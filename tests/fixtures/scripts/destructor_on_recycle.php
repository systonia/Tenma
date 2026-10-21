<?php
// An app whose destructor throws when the interpreter is recycled: that worker restarts, tenma stays
final class App
{
    public function __invoke(): string { return "ok\n"; }
    public function __destruct() { throw new RuntimeException('cleanup failed'); }
}
(new \Http\Server((int) getenv('PORT'), new App()))->start();
