<?php

namespace Threading;

/**
 * A message to a durable function (Threading\Durable) waiting for it, from anywhere: another
 * request, another worker, another process. A signal sent before anyone waits is kept for the next
 * receive() of its name.
 */
final class Signal
{
    /** Sends $payload (anything json_encode() takes) to the function waiting for $name, or keeps it. */
    public static function send(string $name, mixed $payload = null): void
    {
        \Tenma\Internal\requireFiber();
        \Tenma\Internal\await(\tenma_op_durable('send', $name, json_encode($payload, JSON_THROW_ON_ERROR), 0));
    }

    /**
     * Inside a durable function: waits until send() for $name, up to $timeout seconds, and returns the
     * payload, or null after the timeout. Reads like a blocking call; the process may stop and start
     * again meanwhile, the function goes on where it was.
     */
    public static function receive(string $name, float $timeout = 86400.0): mixed
    {
        if (\Tenma\Internal\Current::$durable === null) {
            throw new \LogicException('Signal::receive() only works inside a durable function');
        }
        $payload = Durable::wait("signal:$name", microtime(true) + $timeout);
        return $payload === null ? null : json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
    }
}
