<?php
// For tests/http.rs: static files from public/, everything else to the handler
(new \Http\Server((int) getenv('PORT'), fn (Http\Request $request) => "php: {$request->path}\n", static: __DIR__ . '/public'))->start();
