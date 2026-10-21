<?php
// For tests/http.rs: with --pdo-pgsql native, PDO uses ext/pdo_pgsql and libpq
$url = parse_url((string) getenv('DATABASE_URL'));
$pdo = new PDO(sprintf('pgsql:host=%s;dbname=%s', $url['host'] ?? 'localhost', ltrim($url['path'] ?? '/postgres', '/')), $url['user'] ?? getenv('USER'), $url['pass'] ?? '');
echo str_starts_with($pdo->getAttribute(PDO::ATTR_CLIENT_VERSION), 'tenma') ? "tenma\n" : "libpq {$pdo->getAttribute(PDO::ATTR_CLIENT_VERSION)}\n";
