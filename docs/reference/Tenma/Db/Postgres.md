---
title: "Postgres"
description: "Postgres, asynchronous: while a query runs, only this request's Fiber waits."
order: 100
generated: true
---

`Tenma\Db\Postgres` · final class · namespace [`Tenma\Db`](index.md)

```php
final class Postgres
```

Postgres, asynchronous: while a query runs, only this request's Fiber waits.
Placeholders $1, $2, … as in Postgres; parameters are sent as text, the server determines the type.
Only in Tenma::serve() handlers.

## Methods

### connect()

```php
public static function connect(string $url): \Tenma\Db\Postgres
```

**Parameters**

- `$url` `string`: postgres://user:password@host:5432/database

**Returns** [`Postgres`](Postgres.md)

### query()

```php
public function query(string $sql, array $params = []): array
```

Rows as a list of associative arrays, values with Postgres' JSON types.

**Parameters**

- `$sql` `string`
- `$params` `list<mixed>`

**Returns** `list<array<string, mixed>>`

### first()

```php
public function first(string $sql, array $params = []): ?array
```

**Parameters**

- `$sql` `string`
- `$params` `list<mixed>`

**Returns** `array<string, mixed>|null`

### script()

```php
public function script(string $sql): void
```

Several statements separated by semicolons, without parameters, such as a migration file. They run as
one: a failing statement undoes the ones before it, inside transaction() as part of that transaction.
Therefore no COMMIT or ROLLBACK in it, and nothing that refuses to run in a transaction (CREATE INDEX
CONCURRENTLY, VACUUM, CREATE DATABASE). Never build it from input: without parameters, anything in it
is SQL.

**Parameters**

- `$sql` `string`

### execute()

```php
public function execute(string $sql, array $params = []): int
```

For statements without a result; returns the number of affected rows.

**Parameters**

- `$sql` `string`
- `$params` `list<mixed>`

**Returns** `int`

### transaction()

```php
public function transaction(callable $work): mixed
```

Runs $work in a transaction: commit if $work completes, otherwise rollback.

**Parameters**

- `$work` `callable(self): T`

**Returns** `T`

**Template parameters**

- `T`
