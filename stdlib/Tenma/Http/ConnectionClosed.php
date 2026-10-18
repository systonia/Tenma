<?php

namespace Tenma\Http;

/** The client is gone: streams and WebSockets throw this on the next write. */
final class ConnectionClosed extends \RuntimeException {}
