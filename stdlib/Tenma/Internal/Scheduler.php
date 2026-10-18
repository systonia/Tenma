<?php

namespace Tenma\Internal;

/**
 * Who waits for what. Every sleeping Fiber waits for a token (an op, a task, an all());
 * a wake with a different token is stale and gets dropped. This lets Tenma also interrupt a
 * waiting Fiber, for example to cancel it, without it being woken again later.
 */
final class Scheduler
{
    /** @var array<int, array{string, int|null}> Fiber ID → [token, request] */
    public static array $waiting = [];
    /** @var list<array{\Fiber, string, mixed}|null> */
    public static array $ready = [];
    public static int $head = 0;
    public static int $tokens = 0;
    /** @var array<int, \Tenma\Task> running tasks by task ID */
    public static array $tasks = [];
    /** @var array<int, \Tenma\Task> Fiber ID → task */
    public static array $taskOfFiber = [];
    /** @var array<int, array<int, \Tenma\Task|\Tenma\Timer|\Tenma\Subscription>> Request → running tasks, timers and subscriptions, by object ID */
    public static array $ofRequest = [];
    /** @var array<int, \Throwable> Request → the error its work ended with, while some of that work still runs */
    public static array $ended = [];
    /** @var array<int, mixed> Values of Tenma\AsyncLocal outside any task (script, start of a request) */
    public static array $rootContext = [];
}
