<?php

namespace Tenma;

/**
 * The task or request was cancelled, for example because a sibling in all() failed or exit()
 * ran. Deliberately not a RuntimeException: a catch for failed ops should not swallow it.
 */
class Cancelled extends \Exception {}
