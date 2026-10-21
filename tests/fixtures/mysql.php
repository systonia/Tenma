<?php
// For tests/http.rs: PDO MySQL and mysqli (mysqlnd, over PHP's socket streams) suspend only the fiber.
// Needs a running MySQL (MYSQL_URL).
use Http\{Request, Response, Server};
use Threading\Task;

$url = parse_url((string) getenv('MYSQL_URL'));
$host = $url['host'] ?? '127.0.0.1';
$port = $url['port'] ?? 3306;
$user = $url['user'] ?? 'root';
$password = $url['pass'] ?? '';
$database = ltrim($url['path'] ?? '/tenma', '/');

new Server((int) getenv('PORT'), function (Request $request) use ($host, $port, $user, $password, $database) {
    $started = microtime(true);
    $seconds = fn () => round(microtime(true) - $started, 1) . 's';
    switch ($request->path) {
        case '/setup':
            $pdo = new PDO("mysql:host=$host;port=$port;dbname=$database", $user, $password);
            $pdo->exec('DROP TABLE IF EXISTS tenma_test');
            $pdo->exec('CREATE TABLE tenma_test (id INT PRIMARY KEY AUTO_INCREMENT, name VARCHAR(50), amount DECIMAL(8,2))');
            return "ready\n";
        case '/pdo':
            // Two queries of half a second at once, through two connections: half a second in all
            $sleep = function () use ($host, $port, $user, $password, $database): string {
                $pdo = new PDO("mysql:host=$host;port=$port;dbname=$database", $user, $password);
                $statement = $pdo->prepare('SELECT SLEEP(?) AS slept, ? AS name');
                $statement->execute([0.5, 'first']);
                return $statement->fetch(PDO::FETCH_ASSOC)['name'];
            };
            $names = Task::all($sleep, $sleep);
            return implode(',', $names) . ' ' . $seconds() . "\n";
        case '/mysqli':
            $sleep = function () use ($host, $port, $user, $password, $database): string {
                $mysqli = new mysqli($host, $user, $password, $database, $port);
                $result = $mysqli->query("SELECT SLEEP(0.5) AS slept, 'second' AS name");
                return $result->fetch_assoc()['name'];
            };
            $names = Task::all($sleep, $sleep);
            return implode(',', $names) . ' ' . $seconds() . "\n";
        case '/insert':
            $pdo = new PDO("mysql:host=$host;port=$port;dbname=$database", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->beginTransaction();
            $statement = $pdo->prepare('INSERT INTO tenma_test (name, amount) VALUES (:name, :amount)');
            $statement->execute(['name' => $request->query['name'] ?? 'x', 'amount' => 9.99]);
            $id = $pdo->lastInsertId();
            $pdo->commit();
            $row = $pdo->query("SELECT * FROM tenma_test WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
            return Response::json($row);
    }
    return "ok\n";
})->start();
