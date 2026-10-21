<?php

namespace Tenma\Internal;

/**
 * Thrown where a durable function waits for a timer or a signal: its fiber ends here, and the
 * function runs again from its journal once the wait is over. Deliberately not a RuntimeException;
 * a catch for Throwable in a durable function must rethrow it.
 */
final class Suspended extends \Exception {}
