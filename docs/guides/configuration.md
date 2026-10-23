---
title: Configuration and php.ini
description: PHP settings with -d, -c and php.ini, tenma.json for the options, the defaults Tenma sets, built-in extensions and opcache.
order: 400
---

Tenma embeds PHP 8.5 (thread-safe build) and reads PHP settings the way the PHP command line does. Tenma's own behaviour is set by [command-line options](command-line.md); the script reads its configuration from the environment.

```bash
tenma -c /etc/app/php.ini -d memory_limit=512M -d date.timezone=Europe/Berlin index.php
```

| Source                             | Does                                                     |
| ---------------------------------- | -------------------------------------------------------- |
| `-c FILE\|DIR`                     | reads this `php.ini`, or the `php.ini` in this directory |
| `PHPRC`                            | the directory of the `php.ini`, when `-c` is not given   |
| `php.ini` in the working directory | read when neither `-c` nor `PHPRC` names one             |
| `-d name=value`                    | sets one setting; any number of times                    |
| `ini_set()`                        | changes a setting from the script                        |
| `php_ini_loaded_file()`            | tells which `php.ini` was read, or `false`               |

## tenma.json

Every command-line option can also stand in a `tenma.json` next to the script, or in the file
`--config FILE` names:

```json
{
  "workers": "auto",
  "max-body": "20m",
  "pubsub": "redis://cache",
  "plugins": ["./native/libvirgo.so"],
  "d": ["memory_limit=512M", "date.timezone=Europe/Berlin"],
  "stop-timeout": 10
}
```

Keys are the long option names without `--`; `plugins`, `tls-port` and `d` take lists, `watch` a
boolean. The command line wins over the file for single values; lists add up. Paths in the file
(`plugins`, `tls-cert`, `tls-key`, `c`, `record`) count from the file's directory, paths on the
command line from the working directory. An unknown key stops tenma with its name, as an unknown
option does. `--watch` does not reload it: a changed `tenma.json` needs a restart, because workers,
plugins and the pool are set up once.

`hooks` is the one key that is no option: the build steps that `--watch` and `tenma build` run
([Watch mode](watch.md#build-hooks)). A changed list reloads them under `--watch`.

`tenma check [script.php | tenma.json]` reads the file a script gets (default `index.php`), checks
its options and hooks and loads its plugins as a start would, without running PHP. It warns when a
`tenma.json` in the working directory is not that file, the usual result of an installer run in the
wrong directory, and ends with status 1 at the first problem:

```bash
tenma check public/index.php
# tenma: plugin discord loaded from ./vendor/tenma/discord/lib/libtenma_discord.dylib
# tenma check: public/tenma.json is fine
```

## Where settings come from

In order of precedence, the last one wins:

1. PHP's built-in defaults, with the changes Tenma makes (below).
2. A `php.ini`: the file or directory given with `-c`, otherwise the directory in `PHPRC`, otherwise a `php.ini` in the working directory.
3. `-d name=value` on the command line.
4. `ini_set()` in the script.

A `php.ini` for production:

```ini
memory_limit = 256M
date.timezone = UTC
expose_php = Off
```

## Tenma's defaults

| Setting                                | Tenma                 | Why                                                  |
| -------------------------------------- | --------------------- | ---------------------------------------------------- |
| `display_errors`                       | `0`                   | errors go to the log (stderr), never into a response |
| `log_errors`                           | `1`                   |                                                      |
| `post_max_size`, `upload_max_filesize` | `0` (no limit)        | Tenma limits bodies itself with `--max-body`         |
| `opcache.revalidate_freq`              | `0` under `--watch`   | a saved file is picked up at once                    |
| `opcache.enable`                       | `0` under `--inspect` | the debugger needs the plain opcodes                 |
| `max_execution_time`                   | `0`                   | as on the PHP command line                           |

A `php.ini` or `-d` overrides each of them.

## Opcache and the JIT

Opcache is on, except under `--inspect`. It brings less than under FPM, since each worker compiles the code once at startup anyway, but saves some memory.

The JIT is built on Linux. Enable it with `-d opcache.jit=tracing`. PHP does not build it for thread-safe PHP on Apple Silicon.

## Built-in extensions

Core, date, json, pcre, SPL, Reflection, random, standard, hash, filter, ctype, tokenizer, uri, lexbor, as well as:

| Area               | Extensions                                                                               |
| ------------------ | ---------------------------------------------------------------------------------------- |
| Databases          | PDO with `pdo_mysql`, `pdo_pgsql`, `pdo_sqlite`; `mysqli`, `mysqlnd`, `pgsql`, `sqlite3` |
| Text and data      | `mbstring`, `intl`, `dom`, `xml`, `xmlreader`, `xmlwriter`, `SimpleXML`, `libxml`        |
| Crypto and network | `openssl`, `sodium`, `curl`, `sockets`                                                   |
| Numbers            | `bcmath`, `gmp`, `calendar`                                                              |
| Files and images   | `fileinfo`, `zip`, `zlib`, `gd`, `exif`                                                  |
| Other              | `session`, `posix`, `Zend OPcache`                                                       |

`iconv` is not included; `mbstring` covers most of its uses. `pcntl` is not included either: signals belong to Tenma. `get_loaded_extensions()` lists the extensions of the binary at hand.

## Environment variables

Apart from `PHPRC`, Tenma reads no environment variables of its own. The script reads what it needs with `getenv()`:

```php
<?php

$port = (int) (getenv('PORT') ?: 8080);
$databaseUrl = getenv('DATABASE_URL') ?: 'postgres://localhost/app';
$production = getenv('APP_ENV') === 'production';

$mode = $production ? 'production' : 'development';

new Http\Server($port, fn () => "running in $mode\n")->start();
```

The script reads them once at startup. Changing them needs a restart.

## Behaviour

- Tenma cannot load extensions from `.so` files. Everything is linked in.
- `max_execution_time` is `0`: a handler that computes forever holds up its worker.
- In fiber mode many requests share one interpreter. `ini_set()` inside a handler changes the setting for every request of that worker. Set what you need at script startup.
- `date_default_timezone_set()`, `setlocale()` and `mb_internal_encoding()` are just as global. Call them once at startup.
- In [classic mode](classic-mode.md), what a request changes with `ini_set()` or `set_time_limit()` is reset after the request, to the state after script startup.

See also: [Command line](command-line.md), [Deployment](deployment.md), [Classic mode](classic-mode.md).
