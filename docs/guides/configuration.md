---
title: Configuration and php.ini
description: PHP settings with -d, -c and php.ini, the defaults Tenma sets, built-in extensions and opcache.
order: 140
---

Tenma embeds PHP 8.5 (thread-safe build) and reads PHP settings the way the PHP command line does.

## Where settings come from

In order of precedence, the last one wins:

1. PHP's built-in defaults, with the changes Tenma makes (below).
2. A `php.ini`: the file or directory given with `-c`, otherwise the directory in `PHPRC`, otherwise a `php.ini` in the working directory.
3. `-d name=value` on the command line, any number of times.
4. `ini_set()` in the script. In classic mode, what a request changes with `ini_set()` or `set_time_limit()` is reset after the request to the state after script startup.

```bash
tenma -c /etc/shop/php.ini -d memory_limit=512M -d date.timezone=Europe/Berlin app.php
```

`php_ini_loaded_file()` tells which file was read.

A `php.ini` for production:

```ini
memory_limit = 256M
date.timezone = UTC
expose_php = Off
```

## Tenma's defaults

| Setting | Tenma | Why |
|---|---|---|
| `display_errors` | `0` | errors go to the log (stderr), never into a response |
| `log_errors` | `1` | |
| `post_max_size`, `upload_max_filesize` | `0` (no limit) | Tenma limits bodies itself with `--max-body` |
| `opcache.revalidate_freq` | `0` under `--watch` | a saved file is picked up at once |
| `opcache.enable` | `0` under `--inspect` | the debugger needs the plain opcodes |

Opcache is on otherwise. It brings less than under FPM — each worker compiles the code once at startup anyway — but saves some memory. The JIT is built on Linux and can be enabled with `-d opcache.jit=tracing`; PHP does not build it for thread-safe PHP on Apple Silicon.

`max_execution_time` is `0`, as on the PHP command line: a handler that computes forever holds up its worker.

## Built-in extensions

Core, date, json, pcre, SPL, Reflection, random, standard, hash, filter, ctype, tokenizer, uri, lexbor, as well as:

| Area | Extensions |
|---|---|
| Databases | PDO with `pdo_mysql`, `pdo_pgsql`, `pdo_sqlite`; `mysqli`, `mysqlnd`, `pgsql`, `sqlite3` |
| Text and data | `mbstring`, `intl`, `dom`, `xml`, `xmlreader`, `xmlwriter`, `SimpleXML`, `libxml` |
| Crypto and network | `openssl`, `sodium`, `curl`, `sockets` |
| Numbers | `bcmath`, `gmp`, `calendar` |
| Files and images | `fileinfo`, `zip`, `zlib`, `gd`, `exif` |
| Other | `session`, `posix`, `Zend OPcache` |

`iconv` is not included; `mbstring` covers most of its uses. `get_loaded_extensions()` lists the extensions of the binary at hand. Tenma cannot load extensions from `.so` files; everything is linked in.

## Environment variables

Apart from `PHPRC` for the php.ini, Tenma's own behaviour is set by command-line options; the script reads what it needs with `getenv()`. A common set:

```php
<?php

$port = (int) (getenv('PORT') ?: 8080);
$db = Tenma\Db\Postgres::connect(getenv('DATABASE_URL') ?: 'postgres://app@localhost/app');
$debug = getenv('APP_DEBUG') === '1';

Tenma::serve($port, fn () => $debug ? "debug mode\n" : "ok\n");
```

The script reads them once at startup; changing them needs a restart.

## Settings per request

In fiber mode many requests share one interpreter at the same time, so `ini_set()` inside a handler changes the setting for every request of that worker. Set what you need at script startup. `date_default_timezone_set()`, `setlocale()` and `mb_internal_encoding()` are just as global.

See also: [Command line and deployment](deployment.md), [Classic mode](classic-mode.md).
