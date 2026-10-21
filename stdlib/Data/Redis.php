<?php

namespace Data;

/** Redis, asynchronous, one shared connection per URL and worker. */
final class Redis
{
    private function __construct(private readonly string $url) {}

    /** @param string $url redis://host:6379/0 */
    public static function connect(string $url = 'redis://127.0.0.1:6379'): self
    {
        return new self($url);
    }

    /**
     * Commands that would change the connection for every fiber of the worker (MULTI, SELECT,
     * SUBSCRIBE) or hold it for all of them (BLPOP and the other blocking ones).
     */
    private const SHARED_CONNECTION = [
        'MULTI', 'EXEC', 'DISCARD', 'WATCH', 'UNWATCH', 'SELECT', 'SWAPDB', 'AUTH', 'HELLO', 'RESET', 'QUIT', 'MONITOR',
        'SUBSCRIBE', 'PSUBSCRIBE', 'SSUBSCRIBE', 'UNSUBSCRIBE', 'PUNSUBSCRIBE', 'SUNSUBSCRIBE', 'WAIT', 'WAITAOF',
        'BLPOP', 'BRPOP', 'BLMOVE', 'BRPOPLPUSH', 'BLMPOP', 'BZPOPMIN', 'BZPOPMAX', 'BZMPOP',
    ];

    /** Any Redis command: $redis->command('INCR', 'views:today'). */
    public function command(string $name, string|int|float ...$args): mixed
    {
        $upper = strtoupper($name);
        if (in_array($upper, self::SHARED_CONNECTION, true)
            || ($upper === 'CLIENT' && strtoupper((string) ($args[0] ?? '')) === 'REPLY')
            || (in_array($upper, ['XREAD', 'XREADGROUP'], true) && in_array('BLOCK', array_map(fn ($arg) => strtoupper((string) $arg), $args), true))) {
            throw new \LogicException("Redis $upper does not work on the connection all fibers of the worker share: "
                . 'EVAL a script for atomic steps, the database in the URL (redis://host/2), Messaging\\Channel for messages, polling instead of blocking');
        }
        \Tenma\Internal\requireFiber();
        // Length and bytes: values need not be UTF-8 (serialized or compressed data)
        $packed = '';
        foreach ([$name, ...$args] as $arg) {
            // (string) would round a float to 14 digits
            $arg = is_float($arg) ? json_encode($arg, JSON_THROW_ON_ERROR) : (string) $arg;
            $packed .= strlen($arg) . ':' . $arg;
        }
        return unserialize(\Tenma\Internal\await(\tenma_op_db('redis', $this->url, '', $packed)), ['allowed_classes' => false]);
    }

    public function get(string $key): ?string
    {
        return $this->command('GET', $key);
    }

    public function set(string $key, string $value, ?int $seconds = null): void
    {
        $seconds === null ? $this->command('SET', $key, $value) : $this->command('SET', $key, $value, 'EX', $seconds);
    }
}
