<?php
// For tests/http.rs: configured by the tenma.json next to it
new Http\Server((int) getenv('PORT'), fn (Http\Request $request) => Tenma\Plugin::call('echo', 'upper', $request->query['text'] ?? '') . ' ' . ini_get('memory_limit') . "\n")->start();
